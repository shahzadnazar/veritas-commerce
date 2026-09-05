<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use Illuminate\Support\Facades\DB;

/**
 * Everything a successful payment changes, in one comparable value.
 *
 * The property M9 property 2 asks for is not "the attack returned 4xx". It
 * is "the attack was rejected AND financial truth is unchanged", and those
 * are different claims: a 400 that nevertheless committed inventory, posted
 * a commission or wrote a ledger row has not rejected anything. So every
 * attack in the suite brackets itself with this snapshot.
 *
 * Business columns only, named explicitly, and timestamps left out on
 * purpose. Hashing whole rows would be blunter but would also fail on
 * `updated_at` churn from operations that are legitimately idempotent —
 * re-preparing a payment returns the open attempt as it stands — and a
 * fingerprint that cries wolf gets weakened until it means nothing.
 *
 * Provider webhook events are deliberately absent. Recording that a forged
 * or unmatched event arrived is operational history, and the architecture
 * intends to keep it: a rejected event is evidence, not a mutation. What
 * must not move is below.
 */
trait ObservesFinancialTruth
{
    /**
     * table => the columns whose values are financial truth.
     *
     * @var array<string, array<int, string>>
     */
    private const FINANCIAL_TRUTH = [
        'marketplace_orders' => ['reference', 'status', 'currency', 'grand_total_minor', 'completed_at', 'cancelled_at'],
        'seller_orders' => ['reference', 'status', 'order_total_minor', 'commission_total_minor', 'seller_earning_total_minor'],
        'payment_attempts' => ['public_id', 'provider_reference', 'status', 'provider_status', 'amount_minor', 'currency', 'succeeded_at'],
        'payments' => ['provider_charge_id', 'status', 'amount_minor', 'currency', 'refunded_amount_minor'],
        'payment_transactions' => ['provider_transaction_reference', 'type', 'status', 'amount_minor', 'currency'],
        'seller_ledger_entries' => ['source_key', 'seller_account_id', 'type', 'status', 'amount_minor', 'balance_after_minor'],
        'platform_revenue_entries' => ['source_key', 'seller_account_id', 'type', 'amount_minor'],
        'inventory_balances' => ['offer_id', 'on_hand', 'reserved', 'available'],
        'inventory_movements' => ['offer_id', 'reason', 'on_hand_change', 'reserved_change', 'resulting_on_hand'],
        'inventory_reservations' => ['offer_id', 'reference', 'status', 'quantity'],
        'refunds' => ['reference', 'status', 'amount_minor', 'currency'],
        'refund_allocations' => ['seller_order_id', 'amount_minor', 'commission_reversed_minor', 'earning_reversed_minor'],
        'payout_requests' => ['reference', 'status', 'amount_minor'],
    ];

    /** @return array<string, array<int, string>> */
    protected function financialTruth(): array
    {
        $truth = [];

        foreach (self::FINANCIAL_TRUTH as $table => $columns) {
            $rows = [];

            foreach (DB::table($table)->orderBy('id')->get($columns) as $row) {
                $rows[] = implode('|', array_map(
                    static fn (string $column): string => (string) (((array) $row)[$column] ?? '∅'),
                    $columns,
                ));
            }

            $truth[$table] = $rows;
        }

        return $truth;
    }

    /**
     * @param  array<string, array<int, string>>  $before
     */
    protected function assertFinancialTruthUnchanged(array $before, string $attack): void
    {
        $after = $this->financialTruth();

        foreach ($before as $table => $rows) {
            $this->assertSame(
                $rows,
                $after[$table],
                "{$attack}: the attack was refused but {$table} changed. "
                    .'Rejection and unchanged financial truth are two separate claims, and this is the second one.',
            );
        }
    }
}
