<?php

declare(strict_types=1);

namespace App\Modules\Payments\Queries;

use App\Modules\Payments\Enums\RefundStatus;
use Illuminate\Support\Facades\DB;

/**
 * How many units of each order item have been refunded.
 *
 * Fulfilment needs this and has no business reading the refund tables:
 * the Orders module depends on this query, not on Payments' models, which
 * is what keeps the two extractable.
 *
 * Units, not money. A partial *money* refund — goodwill for a late
 * delivery, say — carries quantity zero and reduces nothing to ship,
 * because the customer is still expecting the goods. Only a refund that
 * returns whole lines reduces what the seller owes them.
 *
 * Refunds that have failed are excluded: the money never left, so the
 * units are still sold.
 */
final class RefundedQuantities
{
    /**
     * @param  array<int, int>  $orderItemIds
     * @return array<int, int> order item id => refunded units
     */
    public function __invoke(array $orderItemIds): array
    {
        if ($orderItemIds === []) {
            return [];
        }

        $holding = array_values(array_map(
            static fn (RefundStatus $status): string => $status->value,
            array_filter(RefundStatus::cases(), static fn (RefundStatus $s): bool => $s->holdsBalance()),
        ));

        /** @var array<int, int> $rows */
        $rows = DB::table('refund_allocations')
            ->whereIn('order_item_id', $orderItemIds)
            ->whereIn(
                'refund_id',
                DB::table('refunds')->whereIn('status', $holding)->select('id'),
            )
            ->groupBy('order_item_id')
            ->selectRaw('order_item_id as item_id, sum(quantity) as units')
            ->pluck('units', 'item_id')
            ->map(static fn (mixed $units): int => (int) $units)
            ->all();

        return $rows;
    }
}
