<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Actions;

use App\Modules\Analytics\Support\AnalyticsDay;
use App\Modules\Events\Enums\InteractionEventType;
use App\Modules\Ledger\Enums\LedgerEntryType;
use App\Modules\Payments\Enums\PaymentStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * One row per seller per day.
 *
 * §52: a seller's numbers are their own. Impressions and clicks are
 * counted only where the seller's own offer was involved, orders only
 * where the seller order is theirs, and earnings only from their own
 * ledger. There is nothing on this row a seller could use to work out
 * what a competitor sold — which is the point, because a seller dashboard
 * that leaks a rival's volume is a competitive-intelligence product
 * nobody agreed to sell.
 *
 * `earnings_minor` is copied from the seller ledger, which is M7's
 * definition of what a seller earned. It is never recomputed from the
 * order snapshots, and never from behaviour (§48, §56).
 */
final class RebuildSellerMetrics
{
    /** @return int rows written */
    public function __invoke(AnalyticsDay $day): int
    {
        $metrics = [];

        foreach ($this->storeViews($day) as $sellerId => $views) {
            $metrics[$sellerId]['store_views'] = $views;
        }

        foreach ($this->offerEngagement($day) as $sellerId => $counts) {
            $metrics[$sellerId] = array_merge($metrics[$sellerId] ?? [], $counts);
        }

        foreach ($this->orders($day) as $sellerId => $counts) {
            $metrics[$sellerId] = array_merge($metrics[$sellerId] ?? [], $counts);
        }

        foreach ($this->fulfilment($day) as $sellerId => $counts) {
            $metrics[$sellerId] = array_merge($metrics[$sellerId] ?? [], $counts);
        }

        foreach ($this->refunds($day) as $sellerId => $counts) {
            $metrics[$sellerId] = array_merge($metrics[$sellerId] ?? [], $counts);
        }

        foreach ($this->earnings($day) as $sellerId => $earnings) {
            $metrics[$sellerId]['earnings_minor'] = $earnings;
        }

        $rows = [];

        foreach ($metrics as $sellerId => $counts) {
            $rows[] = [
                'day' => $day->date,
                'seller_account_id' => $sellerId,
                'store_views' => $counts['store_views'] ?? 0,
                'offer_impressions' => $counts['offer_impressions'] ?? 0,
                'offer_clicks' => $counts['offer_clicks'] ?? 0,
                'orders' => $counts['orders'] ?? 0,
                'units_sold' => $counts['units_sold'] ?? 0,
                'delivered_orders' => $counts['delivered_orders'] ?? 0,
                'refunded_orders' => $counts['refunded_orders'] ?? 0,
                'gross_minor' => $counts['gross_minor'] ?? 0,
                'refunds_minor' => $counts['refunds_minor'] ?? 0,
                'earnings_minor' => $counts['earnings_minor'] ?? 0,
                'computed_at' => now(),
            ];
        }

        DB::transaction(function () use ($day, $rows): void {
            DB::table('daily_seller_metrics')->where('day', $day->date)->delete();

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('daily_seller_metrics')->insert($chunk);
            }
        });

        return count($rows);
    }

    /** @return array<int, int> */
    private function storeViews(AnalyticsDay $day): array
    {
        return $this->keyed(
            DB::table('interaction_events')
                ->where('event_type', InteractionEventType::SellerStoreViewed->value)
                ->whereNotNull('seller_account_id')
                ->where('created_at', '>=', $day->startsAt)
                ->where('created_at', '<', $day->endsAt)
                ->groupBy('seller_account_id')
                ->selectRaw('seller_account_id, count(*) as total')
                ->get(),
        );
    }

    /**
     * Impressions and clicks on this seller's own offers.
     *
     * The `offer_id` on the event is what scopes it. An event that names a
     * product but no offer belongs to the canonical product and is counted
     * in daily_product_metrics — attributing it to whichever seller
     * happens to hold the buy box would credit one seller for interest the
     * whole listing generated.
     *
     * @return array<int, array<string, int>>
     */
    private function offerEngagement(AnalyticsDay $day): array
    {
        $columns = [
            InteractionEventType::SearchResultShown->value => 'offer_impressions',
            InteractionEventType::SearchResultClicked->value => 'offer_clicks',
        ];

        $rows = DB::table('interaction_events')
            ->whereNotNull('offer_id')
            ->whereNotNull('seller_account_id')
            ->whereIn('event_type', array_keys($columns))
            ->where('created_at', '>=', $day->startsAt)
            ->where('created_at', '<', $day->endsAt)
            ->groupBy('seller_account_id', 'event_type')
            ->selectRaw('seller_account_id, event_type, count(*) as total')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $column = $columns[(string) $row->event_type] ?? null;

            if ($column !== null) {
                $counts[(int) $row->seller_account_id][$column] = (int) $row->total;
            }
        }

        return $counts;
    }

    /**
     * Paid orders, units and gross, from the immutable snapshots.
     *
     * @return array<int, array<string, int>>
     */
    private function orders(AnalyticsDay $day): array
    {
        $rows = DB::table('seller_orders as so')
            ->join('payments as pay', 'pay.marketplace_order_id', '=', 'so.marketplace_order_id')
            ->join('order_items as oi', 'oi.seller_order_id', '=', 'so.id')
            ->whereIn('pay.status', [
                PaymentStatus::Captured->value,
                PaymentStatus::PartiallyRefunded->value,
            ])
            ->whereNotNull('pay.captured_at')
            ->where('pay.captured_at', '>=', $day->startsAt)
            ->where('pay.captured_at', '<', $day->endsAt)
            ->groupBy('so.seller_account_id')
            ->selectRaw(
                'so.seller_account_id, count(distinct so.id) as orders, '.
                'sum(oi.quantity) as units, sum(oi.line_total_minor) as gross'
            )
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row->seller_account_id] = [
                'orders' => (int) $row->orders,
                'units_sold' => (int) $row->units,
                'gross_minor' => (int) $row->gross,
            ];
        }

        return $counts;
    }

    /**
     * Orders that reached the customer on this day.
     *
     * Keyed on `delivered_at`, not on when the order was placed: a
     * delivery rate that credits the day of purchase says nothing about
     * how long the seller took.
     *
     * @return array<int, array<string, int>>
     */
    private function fulfilment(AnalyticsDay $day): array
    {
        $rows = DB::table('seller_orders')
            ->whereNotNull('delivered_at')
            ->where('delivered_at', '>=', $day->startsAt)
            ->where('delivered_at', '<', $day->endsAt)
            ->groupBy('seller_account_id')
            ->selectRaw('seller_account_id, count(*) as total')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row->seller_account_id] = ['delivered_orders' => (int) $row->total];
        }

        return $counts;
    }

    /**
     * Refund value attributed to the seller whose items were returned.
     *
     * A refund is issued against a marketplace order, which may span
     * several sellers, so it is apportioned by the reversal entries the
     * refund actually wrote to each seller's ledger — the same records the
     * seller's balance is built from. Splitting it evenly, or by order
     * share, would produce a number that disagrees with the ledger.
     *
     * @return array<int, array<string, int>>
     */
    private function refunds(AnalyticsDay $day): array
    {
        $reversals = DB::table('seller_ledger_entries')
            ->where('type', LedgerEntryType::RefundReversal->value)
            ->where('created_at', '>=', $day->startsAt)
            ->where('created_at', '<', $day->endsAt)
            ->groupBy('seller_account_id')
            ->selectRaw('seller_account_id, sum(amount_minor) as total, count(distinct seller_order_id) as orders')
            ->get();

        $counts = [];

        foreach ($reversals as $row) {
            $counts[(int) $row->seller_account_id] = [
                // Reversals are stored negative. A "refunds" figure reads
                // as a positive amount returned, so the sign is flipped
                // once, here, rather than in every dashboard.
                'refunds_minor' => -(int) $row->total,
                'refunded_orders' => (int) $row->orders,
            ];
        }

        return $counts;
    }

    /**
     * What each seller earned, straight from the ledger. §56.
     *
     * @return array<int, int>
     */
    private function earnings(AnalyticsDay $day): array
    {
        return $this->keyed(
            DB::table('seller_ledger_entries')
                ->whereIn('type', [
                    LedgerEntryType::SaleEarning->value,
                    LedgerEntryType::RefundReversal->value,
                    LedgerEntryType::Adjustment->value,
                ])
                ->where('created_at', '>=', $day->startsAt)
                ->where('created_at', '<', $day->endsAt)
                ->groupBy('seller_account_id')
                ->selectRaw('seller_account_id, sum(amount_minor) as total')
                ->get(),
        );
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     * @return array<int, int>
     */
    private function keyed($rows): array
    {
        $keyed = [];

        foreach ($rows as $row) {
            $keyed[(int) $row->seller_account_id] = (int) $row->total;
        }

        return $keyed;
    }
}
