<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Queries;

use App\Modules\Analytics\Data\MetricSeries;
use App\Modules\Analytics\Data\MetricTotal;
use App\Modules\Analytics\Enums\AnalyticsPeriod;
use App\Modules\Analytics\Support\AnalyticsDay;
use Illuminate\Support\Facades\DB;

/**
 * The platform's own dashboard, read entirely from the projections.
 *
 * §29 for analytics: the page does not aggregate the event log, does not
 * join orders to payments and does not sum a ledger. It reads rows that
 * `analytics:rebuild` already computed, which is what keeps a dashboard
 * from becoming the slowest page in the admin area — and what guarantees
 * two people looking at the same day see the same number.
 *
 * §71: currency is a filter. Every money figure returned is in the one
 * currency asked for, and nothing anywhere adds two currencies together.
 */
final class GetMarketplaceAnalytics
{
    /** @return array<string, mixed> */
    public function __invoke(AnalyticsPeriod $period, string $currency): array
    {
        $currency = strtoupper($currency);
        $days = $period->dayRange();
        $dates = array_map(static fn (AnalyticsDay $day): string => $day->date, $days);

        $rows = $this->rows($dates, $currency);
        $previous = $this->sums(
            array_map(static fn (AnalyticsDay $day): string => $day->date, $period->previousDayRange()),
            $currency,
        );
        $current = $this->sumRows($rows);

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
            'funnel' => $this->funnel($current),
            'coverage' => $this->coverage($dates, $rows),
        ];
    }

    /**
     * @param  array<int, string>  $dates
     * @return array<string, array<string, int>> keyed by day
     */
    private function rows(array $dates, string $currency): array
    {
        if ($dates === []) {
            return [];
        }

        $rows = DB::table('daily_marketplace_metrics')
            ->whereIn('day', $dates)
            ->where('currency', $currency)
            ->orderBy('day')
            ->get();

        $keyed = [];

        foreach ($rows as $row) {
            $record = (array) $row;
            unset($record['id'], $record['day'], $record['currency'], $record['computed_at']);

            $keyed[(string) $row->day] = array_map(intval(...), $record);
        }

        return $keyed;
    }

    /**
     * @param  array<int, string>  $dates
     * @return array<string, int>
     */
    private function sums(array $dates, string $currency): array
    {
        return $this->sumRows($this->rows($dates, $currency));
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
            ['gmv_minor', 'GMV', true],
            ['refunds_minor', 'Refunds', true],
            ['commission_minor', 'Platform commission', true],
            ['paid_orders', 'Paid orders', false],
            ['new_customers', 'New customers', false],
            ['unique_visitors', 'Unique visitors', false],
            ['product_views', 'Product views', false],
            ['searches', 'Searches', false],
        ];

        $totals = [];

        foreach ($definitions as [$key, $label, $isMoney]) {
            $totals[] = new MetricTotal(
                key: $key,
                label: $label,
                value: $current[$key] ?? 0,
                previous: $previous === [] ? null : ($previous[$key] ?? 0),
                isMoney: $isMoney,
                currency: $isMoney ? $currency : null,
            );
        }

        // Net sales is GMV less refunds — M7's definition, restated here
        // rather than stored, because a stored total and its two
        // components are three numbers that can disagree.
        $netCurrent = ($current['gmv_minor'] ?? 0) - ($current['refunds_minor'] ?? 0);
        $netPrevious = $previous === []
            ? null
            : ($previous['gmv_minor'] ?? 0) - ($previous['refunds_minor'] ?? 0);

        array_splice($totals, 2, 0, [new MetricTotal(
            key: 'net_sales_minor',
            label: 'Net sales',
            value: $netCurrent,
            previous: $netPrevious,
            isMoney: true,
            currency: $currency,
        )]);

        return $totals;
    }

    /**
     * @param  array<int, string>  $dates
     * @param  array<string, array<string, int>>  $rows
     * @return array<int, MetricSeries>
     */
    private function series(array $dates, array $rows): array
    {
        $definitions = [
            ['gmv_minor', 'GMV', true],
            ['paid_orders', 'Paid orders', false],
            ['unique_visitors', 'Unique visitors', false],
            ['product_views', 'Product views', false],
            ['searches', 'Searches', false],
            ['cart_adds', 'Cart adds', false],
        ];

        return array_map(
            fn (array $definition): MetricSeries => MetricSeries::fill(
                key: $definition[0],
                label: $definition[1],
                days: $dates,
                byDay: $this->column($rows, $definition[0]),
                isMoney: $definition[2],
            ),
            $definitions,
        );
    }

    /**
     * @param  array<string, array<string, int>>  $rows
     * @return array<string, int>
     */
    private function column(array $rows, string $key): array
    {
        $values = [];

        foreach ($rows as $day => $record) {
            $values[$day] = $record[$key] ?? 0;
        }

        return $values;
    }

    /**
     * The one funnel the event log can honestly support.
     *
     * Each rate is stated against the step above it and against nothing
     * else, and every step is a count of events that actually happened.
     * The step-to-step ratios do not multiply out to the overall rate,
     * because visitors skip steps — saying so here is better than
     * publishing a tidy number that is wrong.
     *
     * @param  array<string, int>  $totals
     * @return array<string, mixed>
     */
    private function funnel(array $totals): array
    {
        $steps = [
            ['key' => 'visitors', 'label' => 'Visitors', 'value' => $totals['unique_visitors'] ?? 0],
            ['key' => 'product_views', 'label' => 'Product views', 'value' => $totals['product_views'] ?? 0],
            ['key' => 'cart_adds', 'label' => 'Cart adds', 'value' => $totals['cart_adds'] ?? 0],
            ['key' => 'checkouts', 'label' => 'Checkouts started', 'value' => $totals['checkouts_started'] ?? 0],
            ['key' => 'paid_orders', 'label' => 'Paid orders', 'value' => $totals['paid_orders'] ?? 0],
        ];

        foreach ($steps as $index => $step) {
            $previous = $index === 0 ? null : $steps[$index - 1]['value'];

            $steps[$index]['rate'] = $previous === null || $previous === 0
                ? null
                : round($step['value'] / $previous * 100, 1);
        }

        return ['steps' => $steps];
    }

    /**
     * Which days actually have a row.
     *
     * Surfaced rather than hidden: a chart that draws zeroes for days the
     * rebuild never ran looks like a marketplace that stopped trading, and
     * the difference matters enough to say out loud.
     *
     * @param  array<int, string>  $dates
     * @param  array<string, array<string, int>>  $rows
     * @return array<string, mixed>
     */
    private function coverage(array $dates, array $rows): array
    {
        $missing = array_values(array_filter(
            $dates,
            static fn (string $date): bool => ! isset($rows[$date]),
        ));

        return [
            'days' => count($dates),
            'computed' => count($dates) - count($missing),
            'missing' => $missing,
            'complete' => $missing === [],
        ];
    }
}
