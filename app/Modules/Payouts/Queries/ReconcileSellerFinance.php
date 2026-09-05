<?php

declare(strict_types=1);

namespace App\Modules\Payouts\Queries;

use App\Modules\Payouts\Enums\PayoutAllocationStatus;
use App\Modules\Payouts\Enums\PayoutStatus;
use Illuminate\Support\Facades\DB;

/**
 * Checks that the money adds up, and never touches anything. §40 and §41.
 *
 * Five properties, each of which must hold for every seller and every
 * payout on the platform. They are stated as SQL rather than as prose
 * because a reconciliation that agrees with the code by construction
 * proves nothing — these queries deliberately re-derive the same facts a
 * different way:
 *
 *   1. A PAID payout's amount equals the ledger debit that settled it.
 *   2. A PAID payout's amount equals the allocations that were settled
 *      against it.
 *   3. An OPEN payout's amount equals the allocations still held for it.
 *   4. A REJECTED or CANCELLED payout holds nothing.
 *   5. A seller's running balance (`balance_after_minor` on the last row)
 *      equals the sum of every entry before it.
 *
 * Nothing here writes. §40 is explicit that a reconciliation must not
 * quietly mutate records to make itself pass: a discrepancy is a fact
 * about the system that somebody has to look at, and a sweep that silently
 * repaired it would destroy the evidence of whatever caused it.
 */
final class ReconcileSellerFinance
{
    /**
     * @return array<int, array{check: string, subject: string, detail: string}>
     */
    public function __invoke(string $currency = 'USD'): array
    {
        return array_merge(
            $this->paidPayoutsMatchTheirDebits($currency),
            $this->paidPayoutsMatchTheirAllocations($currency),
            $this->openPayoutsMatchTheirHolds($currency),
            $this->endedPayoutsHoldNothing($currency),
            $this->runningBalancesAddUp($currency),
        );
    }

    /** @return array<int, array{check: string, subject: string, detail: string}> */
    private function paidPayoutsMatchTheirDebits(string $currency): array
    {
        $rows = DB::table('payout_requests as p')
            ->leftJoin('seller_ledger_entries as e', function ($join): void {
                $join->on('e.payout_request_id', '=', 'p.id')->where('e.type', '=', 'payout');
            })
            ->where('p.currency', $currency)
            ->where('p.status', PayoutStatus::Paid->value)
            ->groupBy('p.id', 'p.reference', 'p.amount_minor')
            ->havingRaw('coalesce(sum(e.amount_minor), 0) <> -p.amount_minor')
            ->selectRaw('p.reference, p.amount_minor, coalesce(sum(e.amount_minor), 0) as debited')
            ->get();

        return $rows->map(static fn (object $row): array => [
            'check' => 'paid_payout_has_one_matching_debit',
            'subject' => (string) $row->reference,
            'detail' => sprintf(
                'paid %d but the ledger debit totals %d',
                (int) $row->amount_minor,
                -(int) $row->debited,
            ),
        ])->all();
    }

    /** @return array<int, array{check: string, subject: string, detail: string}> */
    private function paidPayoutsMatchTheirAllocations(string $currency): array
    {
        $rows = DB::table('payout_requests as p')
            ->leftJoin('payout_allocations as a', function ($join): void {
                $join->on('a.payout_request_id', '=', 'p.id')
                    ->where('a.status', '=', PayoutAllocationStatus::Settled->value);
            })
            ->where('p.currency', $currency)
            ->where('p.status', PayoutStatus::Paid->value)
            ->groupBy('p.id', 'p.reference', 'p.amount_minor')
            ->havingRaw('coalesce(sum(a.amount_minor), 0) <> p.amount_minor')
            ->selectRaw('p.reference, p.amount_minor, coalesce(sum(a.amount_minor), 0) as settled')
            ->get();

        return $rows->map(static fn (object $row): array => [
            'check' => 'paid_payout_matches_settled_allocations',
            'subject' => (string) $row->reference,
            'detail' => sprintf(
                'paid %d but %d was allocated',
                (int) $row->amount_minor,
                (int) $row->settled,
            ),
        ])->all();
    }

    /** @return array<int, array{check: string, subject: string, detail: string}> */
    private function openPayoutsMatchTheirHolds(string $currency): array
    {
        $rows = DB::table('payout_requests as p')
            ->leftJoin('payout_allocations as a', function ($join): void {
                $join->on('a.payout_request_id', '=', 'p.id')
                    ->where('a.status', '=', PayoutAllocationStatus::Held->value);
            })
            ->where('p.currency', $currency)
            ->whereIn('p.status', PayoutStatus::openValues())
            ->groupBy('p.id', 'p.reference', 'p.amount_minor')
            ->havingRaw('coalesce(sum(a.amount_minor), 0) <> p.amount_minor')
            ->selectRaw('p.reference, p.amount_minor, coalesce(sum(a.amount_minor), 0) as held')
            ->get();

        return $rows->map(static fn (object $row): array => [
            'check' => 'open_payout_matches_its_reservation',
            'subject' => (string) $row->reference,
            'detail' => sprintf(
                'requested %d but %d is held',
                (int) $row->amount_minor,
                (int) $row->held,
            ),
        ])->all();
    }

    /** @return array<int, array{check: string, subject: string, detail: string}> */
    private function endedPayoutsHoldNothing(string $currency): array
    {
        $rows = DB::table('payout_requests as p')
            ->join('payout_allocations as a', 'a.payout_request_id', '=', 'p.id')
            ->where('p.currency', $currency)
            ->whereIn('p.status', [PayoutStatus::Rejected->value, PayoutStatus::Cancelled->value])
            ->where('a.status', PayoutAllocationStatus::Held->value)
            ->groupBy('p.reference')
            ->selectRaw('p.reference, sum(a.amount_minor) as held')
            ->get();

        return $rows->map(static fn (object $row): array => [
            'check' => 'ended_payout_holds_nothing',
            'subject' => (string) $row->reference,
            'detail' => sprintf('still holds %d after being ended', (int) $row->held),
        ])->all();
    }

    /**
     * The running balance against the sum of the rows that produced it.
     *
     * Catches a ledger written outside PostLedgerEntry, which is the only
     * way `balance_after_minor` can drift — and the one thing that would
     * make every other figure on a statement quietly wrong.
     *
     * @return array<int, array{check: string, subject: string, detail: string}>
     */
    private function runningBalancesAddUp(string $currency): array
    {
        /** @var array<int, object{seller_account_id: int, balance_after_minor: int, total: int}> $rows */
        $rows = DB::select(<<<'SQL'
            select
                last_row.seller_account_id,
                last_row.balance_after_minor,
                totals.total
            from (
                select distinct on (seller_account_id)
                    seller_account_id, balance_after_minor
                from seller_ledger_entries
                where currency = ?
                order by seller_account_id, id desc
            ) as last_row
            join (
                select seller_account_id, sum(amount_minor) as total
                from seller_ledger_entries
                where currency = ?
                group by seller_account_id
            ) as totals on totals.seller_account_id = last_row.seller_account_id
            where last_row.balance_after_minor <> totals.total
        SQL, [$currency, $currency]);

        return array_map(static fn (object $row): array => [
            'check' => 'running_balance_matches_the_entries',
            'subject' => 'seller #'.(int) $row->seller_account_id,
            'detail' => sprintf(
                'last row says %d, the entries sum to %d',
                (int) $row->balance_after_minor,
                (int) $row->total,
            ),
        ], $rows);
    }
}
