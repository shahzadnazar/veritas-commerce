<?php

declare(strict_types=1);

namespace Tests\Feature\Payouts;

use App\Modules\Sellers\Models\SellerAccount;
use Illuminate\Support\Facades\DB;

/**
 * Everything a payout touches, for one seller, in one comparable value.
 *
 * The property M9 property 3 asks for is not "the request threw". It is
 * "the request was refused AND nothing was held, posted or promised", and
 * those are separate claims. A refusal that nevertheless wrote an
 * allocation has reserved money against a seller who was told no, and the
 * next legitimate request will be short by that amount with nothing on any
 * screen explaining why.
 *
 * Scoped to a seller rather than global, because §18 needs the same
 * comparison run against the OTHER seller: whatever happens to A must be
 * invisible in B's ledger, capacity, reservations and history.
 *
 * Business columns only. Timestamps are excluded for the reason the
 * payment observer gives — a fingerprint that fails on `updated_at` churn
 * gets weakened until it means nothing.
 */
trait ObservesPayoutTruth
{
    /**
     * table => the columns that are financial truth, and the column that
     * says which seller each row belongs to.
     *
     * @var array<string, array{owner: string, columns: array<int, string>}>
     */
    private const PAYOUT_TRUTH = [
        'seller_ledger_entries' => [
            'owner' => 'seller_account_id',
            'columns' => ['source_key', 'type', 'status', 'currency', 'amount_minor', 'balance_after_minor', 'payout_request_id'],
        ],
        'payout_requests' => [
            'owner' => 'seller_account_id',
            'columns' => ['reference', 'currency', 'amount_minor', 'status', 'settlement_ref'],
        ],
        'payout_allocations' => [
            'owner' => 'seller_account_id',
            'columns' => ['payout_request_id', 'seller_ledger_entry_id', 'currency', 'amount_minor', 'status'],
        ],
        'payout_accounts' => [
            'owner' => 'seller_account_id',
            'columns' => ['currency', 'status', 'display_label'],
        ],
    ];

    /** @return array<string, array<int, string>> */
    protected function payoutTruth(SellerAccount $seller): array
    {
        $truth = [];

        foreach (self::PAYOUT_TRUTH as $table => $spec) {
            $rows = [];

            $records = DB::table($table)
                ->where($spec['owner'], $seller->id)
                ->orderBy('id')
                ->get($spec['columns']);

            foreach ($records as $record) {
                $rows[] = implode('|', array_map(
                    static fn (string $column): string => (string) (((array) $record)[$column] ?? '∅'),
                    $spec['columns'],
                ));
            }

            $truth[$table] = $rows;
        }

        // Status history is keyed by request rather than by seller, so it
        // is gathered through the seller's own requests.
        $history = DB::table('payout_status_history')
            ->join('payout_requests', 'payout_requests.id', '=', 'payout_status_history.payout_request_id')
            ->where('payout_requests.seller_account_id', $seller->id)
            ->orderBy('payout_status_history.id')
            ->pluck('payout_status_history.to_status')
            ->map(static fn (mixed $status): string => (string) $status)
            ->all();

        $truth['payout_status_history'] = $history;

        // And the derived answer itself, because the whole property is
        // about what the projection says a seller may withdraw.
        $position = $this->positionOf($seller);

        $truth['position'] = [
            'net='.$position->netBalanceMinor(),
            'raw='.$position->rawPayoutCapacityMinor(),
            'withdrawable='.$position->withdrawableMinor(),
            'reserved='.$position->reservedMinor,
            'available='.$position->availableMinor,
        ];

        return $truth;
    }

    /**
     * @param  array<string, array<int, string>>  $before
     */
    protected function assertPayoutTruthUnchanged(array $before, SellerAccount $seller, string $attack): void
    {
        $after = $this->payoutTruth($seller);

        foreach ($before as $table => $rows) {
            $this->assertSame(
                $rows,
                $after[$table],
                "{$attack}: the request was refused but {$table} changed. "
                    .'A refusal that still holds money is not a refusal.',
            );
        }
    }
}
