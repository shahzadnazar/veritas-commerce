<?php

declare(strict_types=1);

namespace App\Modules\Payouts\Queries;

use App\Modules\Ledger\Enums\LedgerEntryStatus;
use App\Modules\Payouts\Data\SellerFinancialPosition;
use App\Modules\Payouts\Enums\PayoutAllocationStatus;
use Illuminate\Support\Facades\DB;

/**
 * THE authoritative answer to "what does this seller have".
 *
 * §1: nothing here reads orders, seller orders, payments, current offer
 * prices or the current commission rate. Two grouped queries — one over
 * immutable ledger rows, one over payout allocations — and the arithmetic
 * is done on the way out.
 *
 * The direction is one-way and it matters:
 *
 *     immutable financial events -> ledger -> this projection -> eligibility
 *
 * Nothing downstream of the projection is allowed to reconstruct a balance
 * of its own. A controller that sums entries, or a React component that
 * subtracts a reservation, is a second implementation of this file that
 * will disagree with it on the day it counts.
 *
 * Currency-aware throughout. Phase 1 runs on USD, but a position that
 * added dollars to euros would be wrong in a way that looks fine until
 * somebody withdraws.
 */
final class GetSellerFinancialPosition
{
    public function __invoke(int $sellerAccountId, string $currency = 'USD'): SellerFinancialPosition
    {
        $currency = strtoupper($currency);

        /*
         * The ledger side. Grouped in SQL rather than iterated, and
         * SIGNED throughout: a reversal is a negative row in the same
         * bucket as the earning it cancels, and clamping a bucket at zero
         * before the totals are taken is how a refund goes missing.
         */
        /** @var array<string, int> $byStatus */
        $byStatus = DB::table('seller_ledger_entries')
            ->where('seller_account_id', $sellerAccountId)
            ->where('currency', $currency)
            ->groupBy('status')
            ->selectRaw('status, sum(amount_minor) as total')
            ->pluck('total', 'status')
            ->map(static fn (mixed $total): int => (int) $total)
            ->all();

        $of = static fn (LedgerEntryStatus $status): int => $byStatus[$status->value] ?? 0;

        /*
         * A reversed entry is excluded entirely — it is not money in any
         * state, which is what `reversed` means — so it is simply never
         * read out of $byStatus.
         *
         * The `paid` bucket holds payout debits and is folded into
         * available, because a payout is money that has already left the
         * spendable pool. Keeping them apart would leave `available`
         * showing every earning a seller ever had.
         */
        $paidMinor = $of(LedgerEntryStatus::Paid);

        $reservedMinor = (int) DB::table('payout_allocations')
            ->where('seller_account_id', $sellerAccountId)
            ->where('currency', $currency)
            ->where('status', PayoutAllocationStatus::Held->value)
            ->sum('amount_minor');

        return new SellerFinancialPosition(
            currency: $currency,
            pendingMinor: $of(LedgerEntryStatus::Pending),
            clearingMinor: $of(LedgerEntryStatus::Clearing),
            availableMinor: $of(LedgerEntryStatus::Available) + $paidMinor,
            reservedMinor: $reservedMinor,
            // Stored negative in the ledger, shown positive: "you have
            // been paid $400", not "you have been paid minus $400".
            paidOutMinor: -$paidMinor,
        );
    }

    /**
     * Positions for many sellers at once, for the admin queue and the
     * reconciliation sweep.
     *
     * Two grouped queries total, whatever the number of sellers — the
     * alternative is one pair per row, which is the N+1 the query-count
     * assertions in §78 exist to catch.
     *
     * @param  array<int, int>  $sellerAccountIds
     * @return array<int, SellerFinancialPosition> keyed by seller account id
     */
    public function forSellers(array $sellerAccountIds, string $currency = 'USD'): array
    {
        if ($sellerAccountIds === []) {
            return [];
        }

        $currency = strtoupper($currency);

        $ledger = DB::table('seller_ledger_entries')
            ->whereIn('seller_account_id', $sellerAccountIds)
            ->where('currency', $currency)
            ->groupBy('seller_account_id', 'status')
            ->selectRaw('seller_account_id, status, sum(amount_minor) as total')
            ->get();

        $reserved = DB::table('payout_allocations')
            ->whereIn('seller_account_id', $sellerAccountIds)
            ->where('currency', $currency)
            ->where('status', PayoutAllocationStatus::Held->value)
            ->groupBy('seller_account_id')
            ->selectRaw('seller_account_id, sum(amount_minor) as total')
            ->pluck('total', 'seller_account_id');

        /** @var array<int, array<string, int>> $buckets */
        $buckets = [];

        foreach ($ledger as $row) {
            $buckets[(int) $row->seller_account_id][(string) $row->status] = (int) $row->total;
        }

        $out = [];

        foreach ($sellerAccountIds as $id) {
            $rows = $buckets[$id] ?? [];
            $paid = $rows[LedgerEntryStatus::Paid->value] ?? 0;

            $out[$id] = new SellerFinancialPosition(
                currency: $currency,
                pendingMinor: $rows[LedgerEntryStatus::Pending->value] ?? 0,
                clearingMinor: $rows[LedgerEntryStatus::Clearing->value] ?? 0,
                availableMinor: ($rows[LedgerEntryStatus::Available->value] ?? 0) + $paid,
                reservedMinor: (int) ($reserved[$id] ?? 0),
                paidOutMinor: -$paid,
            );
        }

        return $out;
    }

    /**
     * The currencies this seller actually holds money in.
     *
     * §12 and §71: a seller with dollars and euros gets two positions and
     * two withdrawable balances, never one number that added them.
     *
     * @return array<int, string>
     */
    public function currenciesFor(int $sellerAccountId): array
    {
        $held = DB::table('seller_ledger_entries')
            ->where('seller_account_id', $sellerAccountId)
            ->distinct()
            ->pluck('currency')
            ->map(static fn (mixed $currency): string => strtoupper((string) $currency))
            ->all();

        return $held === [] ? [strtoupper((string) config('veritas.payouts.currency'))] : $held;
    }
}
