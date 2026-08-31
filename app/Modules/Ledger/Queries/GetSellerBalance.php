<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Queries;

use App\Modules\Ledger\Data\SellerBalance;
use App\Modules\Ledger\Enums\LedgerEntryStatus;
use App\Modules\Ledger\Enums\LedgerEntryType;
use App\Modules\Ledger\Models\SellerLedgerEntry;
use App\Modules\Sellers\Concerns\CurrentSeller;
use App\Support\Money;

/**
 * A balance is derived from the ledger, never read from a column that some
 * other code path increments.
 *
 * A cached `seller_balances` table is a legitimate optimisation later, but
 * it would be a cache reconciled against this query — correctness stays
 * here, in the sum of immutable rows.
 */
final class GetSellerBalance
{
    public function __invoke(int $sellerAccountId, string $currency = 'USD'): SellerBalance
    {
        return CurrentSeller::actingAs($sellerAccountId, function () use ($sellerAccountId, $currency): SellerBalance {
            $rows = SellerLedgerEntry::query()
                ->where('seller_account_id', $sellerAccountId)
                ->where('currency', $currency)
                ->get(['type', 'status', 'amount_minor', 'available_at']);

            $clearing = 0;
            $available = 0;
            $held = 0;
            $now = now();

            foreach ($rows as $row) {
                $amount = (int) $row->amount_minor;

                if ($row->status === LedgerEntryStatus::Reversed) {
                    continue;
                }

                // An open payout reservation is both a debit against the
                // available balance and the figure shown as "held", which is
                // why it counts in two places rather than one.
                if ($row->type === LedgerEntryType::PayoutReservation
                    && $row->status === LedgerEntryStatus::ReservedForPayout) {
                    $held += abs($amount);
                    $available += $amount;

                    continue;
                }

                // Money is only spendable once its clearing deadline has
                // actually passed, whatever the stored status says.
                $stillClearing = $row->available_at !== null && $row->available_at->greaterThan($now);

                if ($stillClearing && $amount > 0) {
                    $clearing += $amount;

                    continue;
                }

                $available += $amount;
            }

            return new SellerBalance(
                clearing: Money::of(max(0, $clearing), $currency),
                available: Money::of(max(0, $available), $currency),
                held: Money::of(max(0, $held), $currency),
            );
        });
    }
}
