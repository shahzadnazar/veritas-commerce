<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Actions;

use App\Modules\Ledger\Enums\LedgerEntryStatus;
use App\Modules\Ledger\Enums\LedgerEntryType;
use App\Modules\Ledger\Models\SellerLedgerEntry;
use App\Modules\Sellers\Concerns\CurrentSeller;
use App\Modules\Sellers\Models\SellerAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only way a row reaches the seller ledger.
 *
 * Locks the seller's rows so the running balance cannot interleave, and
 * derives available_at from the seller's clearing period — which comes from
 * their account override or the platform setting, never from a literal in
 * code.
 */
final class PostLedgerEntry
{
    public function __invoke(
        SellerAccount $seller,
        LedgerEntryType $type,
        int $amountMinor,
        ?LedgerEntryStatus $status = null,
        ?int $sellerOrderId = null,
        ?int $orderItemId = null,
        ?int $payoutRequestId = null,
        ?int $reversesEntryId = null,
        ?Carbon $availableAt = null,
        ?string $note = null,
        string $currency = 'USD',
    ): SellerLedgerEntry {
        $expected = $type->expectedSign();

        if ($expected !== 0 && $amountMinor !== 0 && ($amountMinor > 0 ? 1 : -1) !== $expected) {
            throw new InvalidArgumentException(
                "A {$type->value} entry must be ".($expected > 0 ? 'positive' : 'negative').", got {$amountMinor}."
            );
        }

        return CurrentSeller::actingAs($seller->id, fn (): SellerLedgerEntry => DB::transaction(function () use (
            $seller, $type, $amountMinor, $status, $sellerOrderId, $orderItemId,
            $payoutRequestId, $reversesEntryId, $availableAt, $note, $currency
        ): SellerLedgerEntry {
            $previous = SellerLedgerEntry::query()
                ->where('seller_account_id', $seller->id)
                ->where('currency', $currency)
                ->lockForUpdate()
                ->orderByDesc('id')
                ->first();

            $balanceAfter = ((int) ($previous?->balance_after_minor ?? 0)) + $amountMinor;

            $resolvedStatus = $status ?? $this->defaultStatusFor($type);

            // Earnings clear; everything else is immediate.
            $resolvedAvailableAt = $availableAt;

            if ($resolvedAvailableAt === null) {
                $resolvedAvailableAt = $type === LedgerEntryType::SaleEarning
                    ? now()->addDays($seller->clearingPeriodDays())
                    : now();
            }

            return SellerLedgerEntry::create([
                'seller_account_id' => $seller->id,
                'type' => $type->value,
                'status' => $resolvedStatus->value,
                'currency' => $currency,
                'amount_minor' => $amountMinor,
                'balance_after_minor' => $balanceAfter,
                'seller_order_id' => $sellerOrderId,
                'order_item_id' => $orderItemId,
                'payout_request_id' => $payoutRequestId,
                'reverses_entry_id' => $reversesEntryId,
                'available_at' => $resolvedAvailableAt,
                'note' => $note,
                'created_at' => now(),
            ]);
        }));
    }

    private function defaultStatusFor(LedgerEntryType $type): LedgerEntryStatus
    {
        return match ($type) {
            LedgerEntryType::SaleEarning => LedgerEntryStatus::Clearing,
            LedgerEntryType::PayoutReservation => LedgerEntryStatus::ReservedForPayout,
            LedgerEntryType::Payout => LedgerEntryStatus::Paid,
            default => LedgerEntryStatus::Available,
        };
    }
}
