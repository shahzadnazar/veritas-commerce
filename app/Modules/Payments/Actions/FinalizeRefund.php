<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Ledger\Actions\PostLedgerEntry;
use App\Modules\Ledger\Enums\LedgerEntryStatus;
use App\Modules\Ledger\Enums\LedgerEntryType;
use App\Modules\Ledger\Models\SellerLedgerEntry;
use App\Modules\Orders\Models\SellerOrder;
use App\Modules\Payments\Contracts\PaymentProvider;
use App\Modules\Payments\Data\ProviderRefund;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Enums\PaymentTransactionType;
use App\Modules\Payments\Enums\RefundStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Models\PlatformRevenueEntry;
use App\Modules\Payments\Models\Refund;
use App\Modules\Payments\Models\RefundAllocation;
use App\Modules\Sellers\Models\SellerAccount;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Posts the financial reversals once the provider says the money left.
 *
 * §44 is why this is separate from requesting a refund. Reversing a
 * seller's earning when an admin presses the button, and then having the
 * provider refuse the refund, leaves the seller short of money that never
 * went anywhere — and the platform with a ledger that says it returned
 * funds it still holds.
 *
 * §40: nothing is deleted. The original earning entry stays exactly as it
 * was and a reversal is appended beside it, pointing back at what it
 * reverses. A ledger you can edit is not a ledger.
 *
 * Idempotent by unique `source_key` on both ledgers: a refund event
 * delivered three times posts one reversal.
 */
final class FinalizeRefund
{
    public function __construct(
        private readonly PaymentProvider $provider,
        private readonly PostLedgerEntry $ledger,
    ) {}

    /**
     * @param  ProviderRefund|null  $known  the provider's answer, when the caller already has it
     * @return bool whether this call was the one that finalized it
     */
    public function __invoke(string $providerReference, ?int $providerEventId = null, ?ProviderRefund $known = null): bool
    {
        $providerRefund = $known ?? $this->provider->retrieveRefund($providerReference);

        /** @var Refund|null $refund */
        $refund = Refund::query()
            ->where('provider', $providerRefund->provider)
            ->where('provider_refund_reference', $providerRefund->reference)
            ->first();

        if ($refund === null) {
            return false;
        }

        return DB::transaction(function () use ($refund, $providerRefund): bool {
            /** @var Refund $locked */
            $locked = Refund::query()->whereKey($refund->id)->lockForUpdate()->firstOrFail();

            if ($locked->status->isTerminal()) {
                // Already decided. A replayed event finds the work done.
                return false;
            }

            if ($providerRefund->status === RefundStatus::Failed) {
                $locked->forceFill([
                    'status' => RefundStatus::Failed->value,
                    'failed_at' => now(),
                    'failure_code' => $providerRefund->failure?->code,
                    'failure_message' => $providerRefund->failure?->message === null
                        ? null
                        : mb_substr($providerRefund->failure->message, 0, 500),
                ])->save();

                // Nothing is reversed. The money never left.
                return true;
            }

            if ($providerRefund->status !== RefundStatus::Succeeded) {
                $locked->forceFill(['status' => RefundStatus::Processing->value])->save();

                return false;
            }

            $locked->forceFill([
                'status' => RefundStatus::Succeeded->value,
                'succeeded_at' => now(),
            ])->save();

            $this->postReversals($locked);
            $this->recordTransaction($locked, $providerRefund);
            $this->updatePayment($locked);

            return true;
        });
    }

    /**
     * One reversal per allocation, on both sides of the split.
     *
     * The amounts are the allocation's own — copied from the order item's
     * snapshot when the refund was requested — so a commission rate that
     * changed in between cannot alter what comes back.
     */
    private function postReversals(Refund $refund): void
    {
        /** @var Collection<int, RefundAllocation> $allocations */
        $allocations = RefundAllocation::query()->where('refund_id', $refund->id)->orderBy('id')->get();

        $sellerOrders = SellerOrder::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $allocations->pluck('seller_order_id'))
            ->get()
            ->keyBy('id');

        foreach ($allocations as $allocation) {
            $sellerOrder = $sellerOrders->get($allocation->seller_order_id);

            if ($sellerOrder === null) {
                continue;
            }

            $this->reverseSellerEarning($refund, $allocation, (int) $sellerOrder->seller_account_id);
            $this->reverseCommission($refund, $allocation, (int) $sellerOrder->seller_account_id);
        }
    }

    /**
     * The seller's side of the reversal, through the ledger's own action.
     *
     * Not a hand-written insert. PostLedgerEntry is the only thing that
     * knows how a running balance is taken — it locks the seller's last
     * row rather than an aggregate, which PostgreSQL will not lock at all
     * — and duplicating that here would be a second, quietly different
     * ledger. It also carries the exactly-once guarantee: the source key
     * is unique, so a refund event delivered three times posts one
     * reversal and the later calls get the row that already exists.
     *
     * Status is PENDING and available_at stays null. A reversal is not
     * money the seller may withdraw; it is money being taken back.
     */
    private function reverseSellerEarning(Refund $refund, RefundAllocation $allocation, int $sellerAccountId): void
    {
        if ($allocation->earning_reversed_minor === 0) {
            return;
        }

        $seller = SellerAccount::query()->find($sellerAccountId);

        if ($seller === null) {
            return;
        }

        // The entry this one reverses, so the pair is readable as a pair.
        $original = SellerLedgerEntry::query()
            ->withoutGlobalScopes()
            ->where('source_key', "sale:{$allocation->order_item_id}")
            ->first();

        /*
         * The reversal carries the same state as the entry it cancels.
         *
         * Not always PENDING, which is what M5 posted: a $20 reversal
         * against an earning the seller can already spend has to reduce
         * what they can spend, and one against an earning that is clearing
         * has to clear alongside it. Left pending, the refund would sit
         * invisibly behind a full available balance — and M7 would pay
         * that balance out.
         *
         * Mirroring the original also means the two move together
         * afterwards: the clearing sweep releases the pair, and the net is
         * right at every stage rather than only at the end.
         */
        $status = match ($original?->status) {
            LedgerEntryStatus::Clearing => LedgerEntryStatus::Clearing,
            LedgerEntryStatus::Available => LedgerEntryStatus::Available,
            // Pending, reversed, or an earning this refund cannot find:
            // pending is the state nobody can reach money from.
            default => LedgerEntryStatus::Pending,
        };

        ($this->ledger)(
            seller: $seller,
            type: LedgerEntryType::RefundReversal,
            // Negative: a reversal is a debit against the seller.
            amountMinor: -$allocation->earning_reversed_minor,
            status: $status,
            sellerOrderId: $allocation->seller_order_id,
            orderItemId: $allocation->order_item_id,
            // The original stays exactly as it was. This points at it.
            reversesEntryId: $original?->id,
            // Clearing alongside what it cancels, on the same date.
            availableAt: $status === LedgerEntryStatus::Clearing ? $original->available_at : null,
            note: "Refund {$refund->reference}",
            currency: $allocation->currency,
            sourceKey: "refund:{$refund->id}:earning:{$allocation->order_item_id}",
        );
    }

    private function reverseCommission(Refund $refund, RefundAllocation $allocation, int $sellerAccountId): void
    {
        if ($allocation->commission_reversed_minor === 0) {
            return;
        }

        $key = "refund:{$refund->id}:commission:{$allocation->order_item_id}";

        if (PlatformRevenueEntry::query()->where('source_key', $key)->exists()) {
            return;
        }

        $original = PlatformRevenueEntry::query()
            ->where('source_key', "commission:{$allocation->order_item_id}")
            ->first();

        PlatformRevenueEntry::query()->insertOrIgnore([
            'public_id' => (string) Str::ulid(),
            'marketplace_order_id' => $refund->marketplace_order_id,
            'seller_order_id' => $allocation->seller_order_id,
            'order_item_id' => $allocation->order_item_id,
            'seller_account_id' => $sellerAccountId,
            'type' => PlatformRevenueEntry::TYPE_REVERSAL,
            'currency' => $allocation->currency,
            'amount_minor' => -$allocation->commission_reversed_minor,
            'rate_percent_snapshot' => $original?->rate_percent_snapshot,
            'reverses_entry_id' => $original?->id,
            'source_key' => $key,
            'created_at' => now(),
        ]);
    }

    private function recordTransaction(Refund $refund, ProviderRefund $providerRefund): void
    {
        PaymentTransaction::query()->insertOrIgnore([
            'public_id' => (string) Str::ulid(),
            'marketplace_order_id' => $refund->marketplace_order_id,
            'payment_id' => $refund->payment_id,
            'provider' => $providerRefund->provider,
            'provider_transaction_reference' => $providerRefund->reference,
            'type' => PaymentTransactionType::Refund->value,
            'currency' => $refund->currency,
            // Signed negative, so an order's net position is a sum.
            'amount_minor' => -$refund->amount_minor,
            'status' => 'succeeded',
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * The payment's rolled-up refunded total and status.
     *
     * Summed from the refunds rather than incremented, so a replay that
     * somehow reached here cannot double-count — and so the column can
     * always be rebuilt from the immutable rows underneath it.
     */
    private function updatePayment(Refund $refund): void
    {
        /** @var Payment $payment */
        $payment = Payment::query()->whereKey($refund->payment_id)->lockForUpdate()->firstOrFail();

        $refunded = (int) Refund::query()
            ->where('payment_id', $payment->id)
            ->where('status', RefundStatus::Succeeded->value)
            ->sum('amount_minor');

        $payment->forceFill([
            'refunded_amount_minor' => $refunded,
            'status' => match (true) {
                $refunded >= $payment->amount_minor => PaymentStatus::Refunded->value,
                $refunded > 0 => PaymentStatus::PartiallyRefunded->value,
                default => $payment->status->value,
            },
        ])->save();
    }
}
