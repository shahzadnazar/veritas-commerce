<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Orders\Enums\MarketplaceOrderStatus;
use App\Modules\Orders\Models\MarketplaceOrder;
use App\Modules\Payments\Contracts\PaymentProvider;
use App\Modules\Payments\Data\PreparedPayment;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Exceptions\PaymentRefused;
use App\Modules\Payments\Exceptions\ProviderUnavailable;
use App\Modules\Payments\Models\PaymentAttempt;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Asks the provider to prepare a payment for an order.
 *
 * Every financial value comes from the order and nothing else. §4 is
 * explicit and the reason is not subtle: the amount is the one number a
 * customer would most like to choose, so there is no parameter here for
 * one to arrive through. The order's `grand_total_minor` — frozen at
 * placement, protected by a check constraint, unchanged by any later price
 * move — is what Stripe is told to charge.
 *
 * Preparing twice does not prepare twice. Three things make that true:
 *
 *  1. An open attempt for the order is returned as-is rather than joined by
 *     a second, which a partial unique index enforces so two simultaneous
 *     requests cannot both find nothing.
 *  2. The idempotency key is derived from the attempt, so a retried call to
 *     the provider returns the provider's existing payment.
 *  3. Neither is relied on alone (§5). The database is the authority; the
 *     provider key is the belt under the braces.
 *
 * What this does NOT do is touch inventory. The stock was held at checkout
 * and is held still; preparing a payment is a conversation with a provider,
 * not a change to the marketplace.
 */
final class PreparePayment
{
    public function __construct(
        private readonly PaymentProvider $provider,
        private readonly RecordAttemptTransition $transition,
    ) {}

    /**
     * @return array{attempt: PaymentAttempt, prepared: PreparedPayment}
     *
     * @throws PaymentRefused
     * @throws ProviderUnavailable
     */
    public function __invoke(MarketplaceOrder $order): array
    {
        $this->guardPayable($order);

        $attempt = $this->openAttemptFor($order);

        /*
         * An attempt that already has a provider payment is asked about
         * rather than replaced. A customer refreshing the payment page must
         * land back on the same PaymentIntent — a second one would hold a
         * second authorisation against their card.
         */
        if ($attempt->provider_reference !== null) {
            $existing = $this->provider->retrievePayment($attempt->provider_reference);

            // The provider's view may have moved on since we last looked.
            ($this->transition)(
                $attempt,
                $existing->status,
                source: 'request',
                providerStatus: $existing->providerStatus,
            );

            return [
                'attempt' => $attempt->refresh(),
                'prepared' => new PreparedPayment(
                    provider: $existing->provider,
                    reference: $existing->reference,
                    status: $existing->status,
                    amountMinor: $existing->amountMinor,
                    currency: $existing->currency,
                    clientSecret: $this->provider->preparePayment(
                        $order->grand_total_minor,
                        $order->currency,
                        $this->keyFor($attempt),
                        $this->metadataFor($order, $attempt),
                    )->clientSecret,
                    providerStatus: $existing->providerStatus,
                ),
            ];
        }

        $prepared = $this->provider->preparePayment(
            // From the order. Never from a request, and never recomputed
            // from the cart the order came from — §10.
            $order->grand_total_minor,
            $order->currency,
            $this->keyFor($attempt),
            $this->metadataFor($order, $attempt),
        );

        $attempt->forceFill([
            'provider_reference' => $prepared->reference,
            'provider_status' => $prepared->providerStatus,
        ])->save();

        ($this->transition)(
            $attempt,
            $prepared->status,
            source: 'request',
            providerStatus: $prepared->providerStatus,
            note: 'Provider payment prepared.',
        );

        return ['attempt' => $attempt->refresh(), 'prepared' => $prepared];
    }

    /**
     * Whether this order may be paid at all.
     *
     * An order that has been cancelled, expired or already paid is not a
     * candidate. §10's other half: if an order should no longer be payable,
     * the mechanism is expiry or cancellation — never quietly repricing it.
     */
    private function guardPayable(MarketplaceOrder $order): void
    {
        if ($order->status !== MarketplaceOrderStatus::PendingPayment) {
            // Everything past pending_payment has already been paid for,
            // one way or another; cancelled is the one that has not, and
            // is equally not payable now.
            throw $order->status === MarketplaceOrderStatus::Cancelled
                ? PaymentRefused::orderNotPayable()
                : PaymentRefused::alreadyPaid();
        }

        if ($order->payment_expires_at !== null && $order->payment_expires_at->isPast()) {
            throw PaymentRefused::expired();
        }
    }

    /**
     * The one open attempt for this order, created if there is none.
     *
     * `firstOrCreate` under the partial unique index rather than a
     * read-then-write: two tabs pressing pay at once would otherwise both
     * see nothing and both insert, and one would fail on the constraint
     * with a 500 rather than joining the first.
     */
    private function openAttemptFor(MarketplaceOrder $order): PaymentAttempt
    {
        return DB::transaction(function () use ($order): PaymentAttempt {
            $open = PaymentAttempt::query()
                ->where('marketplace_order_id', $order->id)
                ->whereIn('status', $this->openStatuses())
                ->lockForUpdate()
                ->first();

            if ($open !== null) {
                return $open;
            }

            try {
                return PaymentAttempt::query()->create([
                    'marketplace_order_id' => $order->id,
                    'checkout_attempt_id' => $order->checkout_attempt_id,
                    'provider' => $this->provider->name(),
                    'currency' => $order->currency,
                    'amount_minor' => $order->grand_total_minor,
                    'status' => PaymentAttemptStatus::Created->value,
                    'created_at' => now(),
                ]);
            } catch (QueryException $e) {
                // Lost the race to another request. Its attempt is the one.
                if (($e->errorInfo[0] ?? null) !== '23505') {
                    throw $e;
                }

                return PaymentAttempt::query()
                    ->where('marketplace_order_id', $order->id)
                    ->whereIn('status', $this->openStatuses())
                    ->firstOrFail();
            }
        });
    }

    /** @return array<int, string> */
    private function openStatuses(): array
    {
        return array_values(array_map(
            static fn (PaymentAttemptStatus $s): string => $s->value,
            array_filter(
                PaymentAttemptStatus::cases(),
                static fn (PaymentAttemptStatus $s): bool => $s->isOpen(),
            ),
        ));
    }

    /**
     * The provider's idempotency key.
     *
     * Derived from the attempt's own public id, so it is stable across
     * retries of the same attempt and different for a new one — which is
     * exactly the identity §5 asks for.
     */
    private function keyFor(PaymentAttempt $attempt): string
    {
        return 'attempt:'.$attempt->public_id;
    }

    /**
     * What the provider is told about this payment.
     *
     * References only. §4: no customer name, no email, no address — a
     * payment provider's dashboard is not the place to accumulate a second
     * copy of the customer database, and metadata is visible to anyone with
     * dashboard access.
     *
     * @return array<string, string>
     */
    private function metadataFor(MarketplaceOrder $order, PaymentAttempt $attempt): array
    {
        return array_filter([
            'marketplace_order_id' => (string) $order->id,
            'order_number' => $order->reference,
            'payment_attempt_id' => $attempt->public_id,
            'checkout_attempt_id' => $order->checkout_attempt_id === null
                ? null
                : (string) $order->checkout_attempt_id,
        ], static fn (?string $v): bool => $v !== null && $v !== '');
    }
}
