<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Queries;

use App\Modules\Analytics\Data\MetricSeries;
use App\Modules\Analytics\Data\MetricTotal;
use App\Modules\Analytics\Enums\AnalyticsPeriod;
use App\Modules\Analytics\Support\AnalyticsDay;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * One seller's own numbers, and nothing else's.
 *
 * §52: every query here is scoped by `seller_account_id` in its WHERE
 * clause, not filtered afterwards, so there is no code path that reads a
 * row belonging to another seller and then decides not to show it. The
 * "top products" list is drawn from this seller's own order items, so a
 * product they list alongside four competitors shows *their* units and
 * *their* gross — never the marketplace's total for that product, which
 * would tell them exactly how much their rivals sold.
 *
 * The money columns come from the seller ledger via the projection, which
 * is M7's definition of what they earned (§56). This screen has no opinion
 * about their balance and does not compute one — that is the finance page,
 * and having two places that answer "what am I owed" is how they come to
 * disagree.
 */
final class GetSellerAnalytics
{
    /** @return array<string, mixed> */
    public function __invoke(int $sellerAccountId, AnalyticsPeriod $period, string $currency): array
    {
        $currency = strtoupper($currency);
        $days = $period->dayRange();
        $dates = array_map(static fn (AnalyticsDay $day): string => $day->date, $days);

        $rows = $this->rows($sellerAccountId, $dates);
        $current = $this->sumRows($rows);
        $previousDates = array_map(
            static fn (AnalyticsDay $day): string => $day->date,
            $period->previousDayRange(),
        );
        $previous = $this->sumRows($this->rows($sellerAccountId, $previousDates));

        return [
            'period' => ['value' => $period->value, 'label' => $period->label()],
            'periods' => AnalyticsPeriod::options(),
            'currency' => $currency,
            'timezone' => AnalyticsDay::timezone(),
            'from' => $dates === [] ? null : $dates[0],
            'to' => $dates === [] ? null : $dates[count($dates) - 1],
            'totals' => array_map(
                static fn (MetricTotal $total): array => $total->toArray(),
                $this->totals($current, $previous, $currency),
            ),
            'series' => array_map(
                static fn (MetricSeries $series): array => $series->toArray(),
                $this->series($dates, $rows),
            ),
            'topProducts' => $this->topProducts($sellerAccountId, $dates, $currency),
        ];
    }

    /**
     * @param  array<int, string>  $dates
     * @return array<string, array<string, int>>
     */
    private function rows(int $sellerAccountId, array $dates): array
    {
        if ($dates === []) {
            return [];
        }

        $rows = DB::table('daily_seller_metrics')
            ->where('seller_account_id', $sellerAccountId)
            ->whereIn('day', $dates)
            ->orderBy('day')
            ->get();

        $keyed = [];

        foreach ($rows as $row) {
            $record = (array) $row;
            unset($record['id'], $record['day'], $record['seller_account_id'], $record['computed_at']);

            $keyed[(string) $row->day] = array_map(intval(...), $record);
        }

        return $keyed;
    }

    /**
     * @param  array<string, array<string, int>>  $rows
     * @return array<string, int>
     */
    private function sumRows(array $rows): array
    {
        $totals = [];

        foreach ($rows as $record) {
            foreach ($record as $column => $value) {
                $totals[$column] = ($totals[$column] ?? 0) + $value;
            }
        }

        return $totals;
    }

    /**
     * @param  array<string, int>  $current
     * @param  array<string, int>  $previous
     * @return array<int, MetricTotal>
     */
    private function totals(array $current, array $previous, string $currency): array
    {
        $definitions = [
            ['gross_minor', 'Gross sales', true],
            ['refunds_minor', 'Refunds', true],
            ['earnings_minor', 'Earnings', true],
            ['orders', 'Orders', false],
            ['units_sold', 'Units sold', false],
            ['delivered_orders', 'Delivered', false],
            ['store_views', 'Store views', false],
            ['offer_clicks', 'Listing clicks', false],
        ];

        return array_map(
            static fn (array $definition): MetricTotal => new MetricTotal(
                key: $definition[0],
                label: $definition[1],
                value: $current[$definition[0]] ?? 0,
                previous: $previous === [] ? null : ($previous[$definition[0]] ?? 0),
                isMoney: $definition[2],
                currency: $definition[2] ? $currency : null,
            ),
            $definitions,
        );
    }

    /**
     * @param  array<int, string>  $dates
     * @param  array<string, array<string, int>>  $rows
     * @return array<int, MetricSeries>
     */
    private function series(array $dates, array $rows): array
    {
        $definitions = [
            ['gross_minor', 'Gross sales', true],
            ['orders', 'Orders', false],
            ['units_sold', 'Units sold', false],
            ['store_views', 'Store views', false],
        ];

        return array_map(
            function (array $definition) use ($dates, $rows): MetricSeries {
                $byDay = [];

                foreach ($rows as $day => $record) {
                    $byDay[$day] = $record[$definition[0]] ?? 0;
                }

                return MetricSeries::fill($definition[0], $definition[1], $dates, $byDay, $definition[2]);
            },
            $definitions,
        );
    }

    /**
     * This seller's best-selling products, from their own order items.
     *
     * Deliberately not read from daily_product_metrics: that table counts
     * the whole marketplace, and showing a seller the marketplace's total
     * for a product they happen to list would hand them their
     * competitors' volume.
     *
     * @param  array<int, string>  $dates
     * @return array<int, array<string, mixed>>
     */
    private function topProducts(int $sellerAccountId, array $dates, string $currency): array
    {
        if ($dates === []) {
            return [];
        }

        $from = AnalyticsDay::of($dates[0])->startsAt;
        $to = AnalyticsDay::of($dates[count($dates) - 1])->endsAt;

        $rows = DB::table('order_items as oi')
            ->join('seller_orders as so', 'so.id', '=', 'oi.seller_order_id')
            ->join('payments as pay', 'pay.marketplace_order_id', '=', 'so.marketplace_order_id')
            ->leftJoin('products as p', 'p.id', '=', 'oi.product_id')
            ->where('so.seller_account_id', $sellerAccountId)
            ->where('oi.currency', $currency)
            ->whereNotNull('pay.captured_at')
            ->where('pay.captured_at', '>=', $from)
            ->where('pay.captured_at', '<', $to)
            ->groupBy('oi.product_id', 'p.slug', 'p.title')
            ->selectRaw(
                'oi.product_id, p.slug, p.title, sum(oi.quantity) as units, '.
                'sum(oi.line_total_minor) as gross, sum(oi.seller_earning_amount_minor) as earnings'
            )
            ->orderByRaw('sum(oi.line_total_minor) desc')
            ->orderBy('oi.product_id')
            ->limit(10)
            ->get();

        return $rows->map(static fn ($row): array => [
            'productId' => (int) $row->product_id,
            'slug' => is_string($row->slug) ? $row->slug : null,
            'title' => is_string($row->title) ? $row->title : 'Removed product',
            'units' => (int) $row->units,
            'grossMinor' => (int) $row->gross,
            'gross' => Money::of((int) $row->gross, $currency)->format(),
            'earningsMinor' => (int) $row->earnings,
            'earnings' => Money::of((int) $row->earnings, $currency)->format(),
        ])->all();
    }
}
