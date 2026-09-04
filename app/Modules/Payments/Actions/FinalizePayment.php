<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Orders\Actions\MarkOrderPaid;
use App\Modules\Orders\Enums\MarketplaceOrderStatus;
use App\Modules\Orders\Models\MarketplaceOrder;
use App\Modules\Orders\Models\SellerOrder;
use App\Modules\Payments\Contracts\PaymentProvider;
use App\Modules\Payments\Data\ProviderPayment;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Enums\PaymentTransactionType;
use App\Modules\Payments\Events\PaymentExceptionRaised;
use App\Modules\Payments\Events\PaymentSucceeded;
use App\Modules\Payments\Exceptions\PaymentVerificationFailed;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Payments\Models\PaymentTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/**
 * The one path by which an order becomes paid.
 *
 * PaymentAuthorityTest fails the build if a second caller of MarkOrderPaid
 * appears, and that test is not bureaucracy: a browser redirect carrying
 * `?paid=true`, or a webhook payload believed without being checked, is how
 * a marketplace ships goods for free. So this action does not take an
 * amount, a currency or a status from anybody. It takes a provider
 * reference, asks the provider itself what happened, and compares that
 * answer to what the order says it cost.
 *
 * Four checks before anything moves (§17):
 *
 *  1. The provider says the payment succeeded.
 *  2. The captured amount equals the order's total, to the minor unit.
 *  3. The currency matches.
 *  4. The provider payment belongs to this order's attempt.
 *
 * Any of them failing raises an exception rather than transitioning
 * anything. Marking the order paid anyway — on the grounds that money did
 * seem to arrive — is how a shop discovers at month end that it shipped a
 * £400 order for £4.
 *
 * Everything then happens in one locked transaction, and the notifications
 * happen after it. A confirmation email sent from inside a transaction that
 * later rolls back tells a customer their order was paid when the database
 * has no record of it.
 */
final class FinalizePayment
{
    public function __construct(
        private readonly PaymentProvider $provider,
        private readonly MarkOrderPaid $markPaid,
        private readonly RecordAttemptTransition $transition,
        private readonly RecordFinancialObligations $obligations,
    ) {}

    /**
     * @param  int|null  $providerEventId  the event that authorised this, for the audit trail
     * @return bool whether this call was the one that finalized it
     *
     * @throws PaymentVerificationFailed
     */
    public function __invoke(string $providerReference, ?int $providerEventId = null): bool
    {
        /*
         * The provider's own record, over an authenticated connection.
         *
         * Not the webhook payload. A payload is a notification that
         * something happened; this is the answer to "what is true". They
         * are usually the same and the difference is the entire security
         * model — a forged or replayed payload cannot change what the
         * provider says when asked directly.
         */
        $providerPayment = $this->provider->retrievePayment($providerReference);

        $attempt = PaymentAttempt::query()
            ->where('provider', $providerPayment->provider)
            ->where('provider_reference', $providerReference)
            ->first();

        if ($attempt === null) {
            // Money for a payment this platform never prepared. Nothing to
            // transition and nothing to guess at.
            $this->raise('unknown_reference', $providerReference, null, 'No payment attempt matches this provider reference.');

            return false;
        }

        if ($providerPayment->status !== PaymentAttemptStatus::Succeeded) {
            // Not an error: most events about a payment are not its
            // success. The attempt still follows the provider's state.
            return DB::transaction(function () use ($attempt, $providerPayment, $providerEventId): bool {
                ($this->transition)(
                    $attempt,
                    $providerPayment->status,
                    source: 'provider_event',
                    providerStatus: $providerPayment->providerStatus,
                    providerEventId: $providerEventId,
                );

                return false;
            });
        }

        try {
            return $this->finalize($attempt, $providerPayment, $providerEventId);
        } catch (PaymentVerificationFailed $failure) {
            /*
             * Raised out here, after the transaction has rolled back.
             *
             * Inside it the dispatch would be discarded with everything
             * else — and an operational exception nobody is told about is
             * the same as no exception at all, except that money has moved.
             */
            Event::dispatch(new PaymentExceptionRaised(
                reason: $failure->reason,
                providerReference: $providerPayment->reference,
                marketplaceOrderId: $attempt->marketplace_order_id,
                message: $failure->getMessage(),
                context: $failure->context,
            ));

            throw $failure;
        }
    }

    private function finalize(
        PaymentAttempt $attempt,
        ProviderPayment $providerPayment,
        ?int $providerEventId,
    ): bool {
        return DB::transaction(function () use ($attempt, $providerPayment, $providerEventId): bool {
            /*
             * Locked in a fixed order — attempt, then order — the same
             * order the expiry sweep takes them in, so a payment landing
             * as an order times out has one deterministic winner rather
             * than a deadlock (§22).
             */
            /** @var PaymentAttempt $locked */
            $locked = PaymentAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();

            /** @var MarketplaceOrder $order */
            $order = MarketplaceOrder::query()
                ->whereKey($locked->marketplace_order_id)
                ->lockForUpdate()
                ->firstOrFail();

            // Already done. A replayed event finds the work finished and
            // says so, rather than doing any of it again.
            if ($locked->status === PaymentAttemptStatus::Succeeded) {
                return false;
            }

            $this->verify($order, $locked, $providerPayment);

            ($this->transition)(
                $locked,
                PaymentAttemptStatus::Succeeded,
                source: 'provider_event',
                providerStatus: $providerPayment->providerStatus,
                providerEventId: $providerEventId,
                note: 'Verified against the order total.',
            );

            $locked->forceFill(['method' => $providerPayment->methodDescription])->save();

            $payment = $this->recordPayment($order, $locked, $providerPayment);
            $this->recordTransaction($order, $locked, $payment, $providerPayment);

            /*
             * The order, its seller orders, and the inventory commit — all
             * inside this transaction. The commit turns each hold into a
             * sale in one movement, and is idempotent at the reservation
             * level, so a replay that somehow reached here would find no
             * held rows to claim.
             */
            $this->markPaid->__invoke($order);

            ($this->obligations)($order);

            $lines = SellerOrder::query()
                ->withoutGlobalScopes()
                ->where('marketplace_order_id', $order->id)
                ->orderBy('position')
                ->get()
                ->map(static fn (SellerOrder $sellerOrder): array => [
                    'sellerOrderId' => (int) $sellerOrder->id,
                    'sellerAccountId' => (int) $sellerOrder->seller_account_id,
                    'valueMinor' => (int) $sellerOrder->order_total_minor,
                ])
                ->all();

            $event = new PaymentSucceeded(
                marketplaceOrderId: $order->id,
                orderReference: $order->reference,
                amountMinor: $providerPayment->settledAmountMinor(),
                currency: $providerPayment->currency,
                lines: $lines,
                userId: $order->user_id,
            );

            // After commit: a confirmation email must never describe a
            // transaction that rolled back.
            DB::afterCommit(static fn () => Event::dispatch($event));

            return true;
        });
    }

    /**
     * The four checks. Any failure stops everything.
     *
     * §23's case is the last one: money arriving for an order the platform
     * has already cancelled must NOT silently revive it — the stock has
     * gone back on the shelf and may have been sold. It becomes an
     * operational exception, and probably a refund.
     */
    private function verify(
        MarketplaceOrder $order,
        PaymentAttempt $attempt,
        ProviderPayment $providerPayment,
    ): void {
        if ($attempt->provider_reference !== $providerPayment->reference) {
            throw PaymentVerificationFailed::referenceMismatch(
                (string) $attempt->provider_reference,
                $providerPayment->reference,
            );
        }

        if ($providerPayment->currency !== $order->currency) {
            throw PaymentVerificationFailed::currencyMismatch($order->currency, $providerPayment->currency);
        }

        if ($providerPayment->settledAmountMinor() !== $order->grand_total_minor) {
            throw PaymentVerificationFailed::amountMismatch(
                $order->grand_total_minor,
                $providerPayment->settledAmountMinor(),
            );
        }

        if ($order->status !== MarketplaceOrderStatus::PendingPayment) {
            throw PaymentVerificationFailed::orderNoLongerOpen($order->status->value);
        }
    }

    /** The capture record: one row per order, by unique provider charge id. */
    private function recordPayment(
        MarketplaceOrder $order,
        PaymentAttempt $attempt,
        ProviderPayment $providerPayment,
    ): Payment {
        $chargeId = $providerPayment->chargeReference ?? $providerPayment->reference;

        /** @var Payment|null $existing */
        $existing = Payment::query()->where('provider_charge_id', $chargeId)->first();

        if ($existing !== null) {
            return $existing;
        }

        return Payment::query()->create([
            'marketplace_order_id' => $order->id,
            'payment_attempt_id' => $attempt->id,
            'provider' => $providerPayment->provider,
            'provider_charge_id' => $chargeId,
            'currency' => $providerPayment->currency,
            'amount_minor' => $providerPayment->settledAmountMinor(),
            'refunded_amount_minor' => 0,
            'status' => PaymentStatus::Captured->value,
            'captured_at' => now(),
        ]);
    }

    /** The immutable movement. Unique by (provider, type, reference). */
    private function recordTransaction(
        MarketplaceOrder $order,
        PaymentAttempt $attempt,
        Payment $payment,
        ProviderPayment $providerPayment,
    ): void {
        PaymentTransaction::query()->insertOrIgnore([
            'public_id' => (string) Str::ulid(),
            'marketplace_order_id' => $order->id,
            'payment_id' => $payment->id,
            'payment_attempt_id' => $attempt->id,
            'provider' => $providerPayment->provider,
            'provider_transaction_reference' => $payment->provider_charge_id,
            'type' => PaymentTransactionType::Capture->value,
            'currency' => $providerPayment->currency,
            'amount_minor' => $providerPayment->settledAmountMinor(),
            'status' => 'succeeded',
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * An operational exception, dispatched immediately.
     *
     * Never through afterCommit: these are raised precisely when a
     * transaction is about to be, or has been, abandoned.
     */
    private function raise(string $reason, string $reference, ?int $orderId, string $message): void
    {
        Event::dispatch(new PaymentExceptionRaised($reason, $reference, $orderId, $message));
    }
}
