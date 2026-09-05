<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Actions;

use App\Modules\Ledger\Enums\LedgerEntryStatus;
use App\Modules\Ledger\Models\SellerLedgerEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Starts the clock on a delivered seller order's earnings.
 *
 * PENDING -> CLEARING, with the date the money becomes withdrawable
 * written on each entry. §31 is explicit that this is a step and not a
 * shortcut: an entry never goes straight from pending to available,
 * because "the customer has it" and "the seller may spend it" are
 * different facts and the gap between them is the platform's protection
 * against a refund it has already paid out.
 *
 * NOTHING FINANCIAL MOVES HERE. No amount is written, no entry is created,
 * nothing is recalculated. The money was recorded when the payment was
 * verified, from the purchase snapshot; this is a change of availability
 * on records that already exist, which is why it can be run again safely.
 *
 * Reversals move with the earnings they cancel. A refund issued before
 * delivery leaves a negative entry against the same order, and leaving it
 * behind in PENDING while the positive one cleared would end with the
 * seller's available balance overstated by exactly the refund — the one
 * mistake this whole mechanism exists to prevent.
 */
final class StartClearing
{
    /**
     * @param  int  $sellerOrderId  the delivered seller order
     * @param  Carbon  $availableAt  when the money becomes withdrawable
     * @return int how many entries entered clearing
     */
    public function __invoke(int $sellerOrderId, Carbon $availableAt): int
    {
        return DB::transaction(function () use ($sellerOrderId, $availableAt): int {
            /*
             * A conditional UPDATE rather than a read and a save: two
             * deliveries recorded at once, or a job retried, both narrow
             * to the same rows and only one update matches. The WHERE is
             * the lock.
             */
            return SellerLedgerEntry::query()
                ->withoutGlobalScopes()
                ->where('seller_order_id', $sellerOrderId)
                ->where('status', LedgerEntryStatus::Pending->value)
                ->update([
                    'status' => LedgerEntryStatus::Clearing->value,
                    'available_at' => $availableAt,
                ]);
        });
    }
}
