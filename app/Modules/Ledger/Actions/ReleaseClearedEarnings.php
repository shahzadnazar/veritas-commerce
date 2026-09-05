<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Actions;

use App\Modules\Ledger\Enums\LedgerEntryStatus;
use App\Modules\Ledger\Models\SellerLedgerEntry;
use Illuminate\Support\Facades\DB;

/**
 * Makes cleared earnings withdrawable.
 *
 * CLEARING -> AVAILABLE, for entries whose date has passed. §32, and the
 * whole design of it is that re-running must not duplicate money: it
 * cannot, because no money is written. This changes a status column on
 * records whose amounts were fixed at payment; running it a hundred times
 * makes the same rows available a hundred times, which is once.
 *
 * The conditional UPDATE is the concurrency guard. Two workers sweeping
 * together both narrow to `status = clearing AND available_at <= now`, and
 * whichever commits second matches nothing — so an entry cannot be
 * released twice, and cannot be released at all if a refund reversed it in
 * between, because a reversed entry is no longer clearing.
 *
 * Eligibility is deliberately not "is the payment still good?" as a
 * separate query. The financial records answer it: an earning that was
 * reversed has a reversal beside it and its own status moved, and an
 * earning whose order was refunded is not in `clearing` any more. All the
 * money comes from immutable records, and none of it from a current price
 * or a current commission rate (§33).
 */
final class ReleaseClearedEarnings
{
    /**
     * @param  int|null  $sellerOrderId  narrow to one order, or sweep everything due
     * @return int how many entries became available
     */
    public function __invoke(?int $sellerOrderId = null): int
    {
        return DB::transaction(function () use ($sellerOrderId): int {
            $query = SellerLedgerEntry::query()
                ->withoutGlobalScopes()
                ->where('status', LedgerEntryStatus::Clearing->value)
                ->whereNotNull('available_at')
                ->where('available_at', '<=', now());

            if ($sellerOrderId !== null) {
                $query->where('seller_order_id', $sellerOrderId);
            }

            return $query->update(['status' => LedgerEntryStatus::Available->value]);
        });
    }

    /**
     * The seller orders with money due to be released.
     *
     * Read before the update so the sweep can report and announce what it
     * did — the update itself returns a count, not the rows it touched.
     *
     * @return array<int, int> seller order ids
     */
    public function due(int $limit = 500): array
    {
        return SellerLedgerEntry::query()
            ->withoutGlobalScopes()
            ->where('status', LedgerEntryStatus::Clearing->value)
            ->whereNotNull('available_at')
            ->where('available_at', '<=', now())
            ->whereNotNull('seller_order_id')
            ->distinct()
            ->limit($limit)
            ->pluck('seller_order_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }
}
