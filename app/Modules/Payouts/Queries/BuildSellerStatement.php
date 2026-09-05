<?php

declare(strict_types=1);

namespace App\Modules\Payouts\Queries;

use App\Modules\Ledger\Enums\LedgerEntryStatus;
use App\Modules\Ledger\Enums\LedgerEntryType;
use App\Modules\Payouts\Data\SellerLedgerRow;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The seller's financial statement, straight off the ledger. §35.
 *
 * Not reconstructed from orders. An orders-derived statement disagrees
 * with the balance the moment a refund is issued or a payout settles, and
 * a seller looking at two numbers that do not match has no way to tell
 * which is wrong.
 *
 * THE RUNNING BALANCE (§36) is not recomputed here. Every ledger row
 * already carries `balance_after_minor`, written under the seller's row
 * lock at the moment it was inserted, so the sequence is the one that
 * actually happened rather than one this query invents by re-adding rows
 * in whatever order they come back. That also settles the equal-timestamps
 * problem the section warns about: the order is insertion order, by id,
 * and two entries written in the same second cannot swap.
 *
 * Three queries whatever the page size: the rows, the seller-order
 * references they point at, and the payout references. §78.
 */
final class BuildSellerStatement
{
    /**
     * @return array{rows: array<int, array<string, mixed>>, page: int, lastPage: int, total: int}
     */
    public function __invoke(int $sellerAccountId, string $currency = 'USD', int $perPage = 25): array
    {
        /** @var LengthAwarePaginator<int, stdClass> $paginator */
        $paginator = DB::table('seller_ledger_entries')
            ->where('seller_account_id', $sellerAccountId)
            ->where('currency', $currency)
            // Newest first for reading; the running balance came from
            // insertion order and is carried on the row, so reversing the
            // display does not disturb it.
            ->orderByDesc('id')
            ->select([
                'public_id', 'type', 'status', 'amount_minor', 'balance_after_minor',
                'currency', 'available_at', 'created_at', 'note',
                'seller_order_id', 'payout_request_id',
            ])
            ->paginate($perPage);

        $entries = $paginator->items();

        $orderReferences = $this->referencesFor(
            'seller_orders',
            array_values(array_filter(array_map(
                static fn (object $row): ?int => $row->seller_order_id === null ? null : (int) $row->seller_order_id,
                $entries,
            ))),
        );

        $payoutReferences = $this->referencesFor(
            'payout_requests',
            array_values(array_filter(array_map(
                static fn (object $row): ?int => $row->payout_request_id === null ? null : (int) $row->payout_request_id,
                $entries,
            ))),
        );

        $rows = [];

        foreach ($entries as $entry) {
            $type = LedgerEntryType::from((string) $entry->type);
            $status = LedgerEntryStatus::from((string) $entry->status);

            $payoutReference = $entry->payout_request_id === null
                ? null
                : ($payoutReferences[(int) $entry->payout_request_id] ?? null);

            $orderReference = $entry->seller_order_id === null
                ? null
                : ($orderReferences[(int) $entry->seller_order_id] ?? null);

            $reference = $payoutReference ?? $orderReference;

            $rows[] = (new SellerLedgerRow(
                publicId: (string) $entry->public_id,
                occurredAt: (string) $entry->created_at,
                type: $type->value,
                typeLabel: $type->label(),
                status: $status->value,
                statusLabel: $status->label(),
                description: $this->describe($type, $reference, (string) ($entry->note ?? '')),
                amountMinor: (int) $entry->amount_minor,
                balanceAfterMinor: (int) $entry->balance_after_minor,
                currency: (string) $entry->currency,
                availableAt: $entry->available_at === null ? null : (string) $entry->available_at,
                reference: $reference,
                referenceKind: $payoutReference !== null ? 'payout' : ($orderReference !== null ? 'order' : null),
            ))->toArray();
        }

        return [
            'rows' => $rows,
            'page' => $paginator->currentPage(),
            'lastPage' => $paginator->lastPage(),
            'total' => $paginator->total(),
        ];
    }

    /**
     * "Sale — VC-24081-01", "Payout — PO-14". §75.
     *
     * The note the entry was written with is used where there is one and
     * it says something the type does not.
     */
    private function describe(LedgerEntryType $type, ?string $reference, string $note): string
    {
        $head = match ($type) {
            LedgerEntryType::SaleEarning => 'Sale',
            LedgerEntryType::RefundReversal => 'Refund',
            LedgerEntryType::Payout => 'Payout',
            LedgerEntryType::Adjustment => 'Adjustment',
            LedgerEntryType::Commission => 'Commission',
            LedgerEntryType::Reversal => 'Correction',
        };

        if ($type === LedgerEntryType::Adjustment && $note !== '') {
            return "Adjustment — {$note}";
        }

        return $reference === null ? $head : "{$head} — {$reference}";
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, string>
     */
    private function referencesFor(string $table, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table($table)
            ->whereIn('id', array_unique($ids))
            ->pluck('reference', 'id')
            ->map(static fn (mixed $reference): string => (string) $reference)
            ->all();
    }
}
