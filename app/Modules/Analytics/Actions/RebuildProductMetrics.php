<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Actions;

use App\Modules\Analytics\Support\AnalyticsDay;
use App\Modules\Events\Enums\InteractionEventType;
use App\Modules\Payments\Enums\PaymentStatus;
use Illuminate\Support\Facades\DB;

/**
 * One row per product per day.
 *
 * Keyed by the **canonical product**, not the offer: a product five
 * sellers list has one line in this table, and a seller's own numbers live
 * in daily_seller_metrics instead. Mixing the two would produce a product
 * page whose view count changed depending on which seller was asking.
 *
 * `gross_minor` comes from order items on paid orders — the immutable
 * `line_total_minor` snapshot, not the offer's price today. §48 again: a
 * seller who discounts tomorrow has not changed what yesterday sold for.
 */
final class RebuildProductMetrics
{
    /** @return int rows written */
    public function __invoke(AnalyticsDay $day): int
    {
        $metrics = [];

        foreach ($this->eventCounts($day) as $productId => $counts) {
            $metrics[$productId] = $counts;
        }

        foreach ($this->salesCounts($day) as $productId => $sales) {
            $metrics[$productId] = array_merge($metrics[$productId] ?? [], $sales);
        }

        foreach ($this->wishlistAdds($day) as $productId => $adds) {
            $metrics[$productId]['wishlist_adds'] = $adds;
        }

        $rows = [];

        foreach ($metrics as $productId => $counts) {
            $rows[] = [
                'day' => $day->date,
                'product_id' => $productId,
                'views' => $counts['views'] ?? 0,
                'search_impressions' => $counts['search_impressions'] ?? 0,
                'search_clicks' => $counts['search_clicks'] ?? 0,
                'wishlist_adds' => $counts['wishlist_adds'] ?? 0,
                'cart_adds' => $counts['cart_adds'] ?? 0,
                'purchases' => $counts['purchases'] ?? 0,
                'units_sold' => $counts['units_sold'] ?? 0,
                'gross_minor' => $counts['gross_minor'] ?? 0,
                'computed_at' => now(),
            ];
        }

        DB::transaction(function () use ($day, $rows): void {
            DB::table('daily_product_metrics')->where('day', $day->date)->delete();

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('daily_product_metrics')->insert($chunk);
            }
        });

        return count($rows);
    }

    /**
     * Behavioural counts, one grouped query for the whole day.
     *
     * @return array<int, array<string, int>>
     */
    private function eventCounts(AnalyticsDay $day): array
    {
        $columns = [
            InteractionEventType::ProductViewed->value => 'views',
            InteractionEventType::SearchResultClicked->value => 'search_clicks',
            InteractionEventType::CartItemAdded->value => 'cart_adds',
            // The impression, so a click-through rate has a denominator.
            // A product page view is not an impression — the visitor was
            // already there.
            InteractionEventType::SearchResultShown->value => 'search_impressions',
        ];

        $rows = DB::table('interaction_events')
            ->whereNotNull('product_id')
            ->whereIn('event_type', array_keys($columns))
            ->where('created_at', '>=', $day->startsAt)
            ->where('created_at', '<', $day->endsAt)
            ->groupBy('product_id', 'event_type')
            ->selectRaw('product_id, event_type, count(*) as total')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $column = $columns[(string) $row->event_type] ?? null;

            if ($column === null) {
                continue;
            }

            $counts[(int) $row->product_id][$column] = (int) $row->total;
        }

        return $counts;
    }

    /**
     * What actually sold, from the order snapshots.
     *
     * @return array<int, array<string, int>>
     */
    private function salesCounts(AnalyticsDay $day): array
    {
        $rows = DB::table('order_items as oi')
            ->join('seller_orders as so', 'so.id', '=', 'oi.seller_order_id')
            ->join('payments as pay', 'pay.marketplace_order_id', '=', 'so.marketplace_order_id')
            ->whereIn('pay.status', [
                PaymentStatus::Captured->value,
                PaymentStatus::PartiallyRefunded->value,
            ])
            ->whereNotNull('pay.captured_at')
            ->where('pay.captured_at', '>=', $day->startsAt)
            ->where('pay.captured_at', '<', $day->endsAt)
            ->groupBy('oi.product_id')
            ->selectRaw(
                'oi.product_id, count(distinct so.marketplace_order_id) as orders, '.
                'sum(oi.quantity) as units, sum(oi.line_total_minor) as gross'
            )
            ->get();

        $sales = [];

        foreach ($rows as $row) {
            $sales[(int) $row->product_id] = [
                'purchases' => (int) $row->orders,
                'units_sold' => (int) $row->units,
                'gross_minor' => (int) $row->gross,
            ];
        }

        return $sales;
    }

    /** @return array<int, int> */
    private function wishlistAdds(AnalyticsDay $day): array
    {
        return DB::table('wishlist_items')
            ->where('created_at', '>=', $day->startsAt)
            ->where('created_at', '<', $day->endsAt)
            ->groupBy('product_id')
            ->selectRaw('product_id, count(*) as total')
            ->pluck('total', 'product_id')
            ->mapWithKeys(static fn (mixed $total, mixed $productId): array => [(int) $productId => (int) $total])
            ->all();
    }
}
