<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Queries;

use App\Modules\Analytics\Data\MetricSeries;
use App\Modules\Analytics\Enums\AnalyticsPeriod;
use App\Modules\Analytics\Support\AnalyticsDay;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * How one canonical product, or the catalogue's best, actually performed.
 *
 * Marketplace-wide by definition: this counts the product, not a seller's
 * offer of it, which is why it is an admin surface. A seller asking the
 * same question gets GetSellerAnalytics, scoped to their own items — the
 * two are separate classes precisely so a seller screen cannot
 * accidentally render this one.
 *
 * The view-to-purchase rate is the number that earns the page: a product
 * with thousands of views and no sales has a price problem, a photo
 * problem or a stock problem, and none of those is visible from the sales
 * report alone.
 */
final class GetProductAnalytics
{
    private const LIST_SIZE = 25;

    /** @return array<string, mixed> */
    public function __invoke(AnalyticsPeriod $period, string $currency, ?int $productId = null): array
    {
        $currency = strtoupper($currency);
        $dates = array_map(
            static fn (AnalyticsDay $day): string => $day->date,
            $period->dayRange(),
        );

        return [
            'period' => ['value' => $period->value, 'label' => $period->label()],
            'periods' => AnalyticsPeriod::options(),
            'currency' => $currency,
            'timezone' => AnalyticsDay::timezone(),
            'from' => $dates === [] ? null : $dates[0],
            'to' => $dates === [] ? null : $dates[count($dates) - 1],
            'topSellers' => $this->ranked($dates, $currency, 'gross_minor'),
            'topViewed' => $this->ranked($dates, $currency, 'views'),
            'product' => $productId === null ? null : $this->product($productId, $dates, $currency),
        ];
    }

    /**
     * @param  array<int, string>  $dates
     * @return array<int, array<string, mixed>>
     */
    private function ranked(array $dates, string $currency, string $by): array
    {
        if ($dates === []) {
            return [];
        }

        $rows = DB::table('daily_product_metrics as m')
            ->leftJoin('products as p', 'p.id', '=', 'm.product_id')
            ->whereIn('m.day', $dates)
            ->groupBy('m.product_id', 'p.slug', 'p.title')
            ->selectRaw(
                'm.product_id, p.slug, p.title, sum(m.views) as views, '.
                'sum(m.cart_adds) as cart_adds, sum(m.units_sold) as units_sold, '.
                'sum(m.gross_minor) as gross_minor, sum(m.purchases) as purchases'
            )
            ->orderByRaw('sum(m.'.($by === 'gross_minor' ? 'gross_minor' : 'views').') desc')
            ->orderBy('m.product_id')
            ->limit(self::LIST_SIZE)
            ->get();

        return $rows->map(static function ($row) use ($currency): array {
            $views = (int) $row->views;
            $purchases = (int) $row->purchases;

            return [
                'productId' => (int) $row->product_id,
                'slug' => is_string($row->slug) ? $row->slug : null,
                'title' => is_string($row->title) ? $row->title : 'Removed product',
                'views' => $views,
                'cartAdds' => (int) $row->cart_adds,
                'unitsSold' => (int) $row->units_sold,
                'purchases' => $purchases,
                'grossMinor' => (int) $row->gross_minor,
                'gross' => Money::of((int) $row->gross_minor, $currency)->format(),
                // Null on no views: a product nobody saw has no conversion
                // rate, and 0% would read as a product people rejected.
                'conversionRate' => $views === 0 ? null : round($purchases / $views * 100, 2),
            ];
        })->all();
    }

    /**
     * @param  array<int, string>  $dates
     * @return array<string, mixed>
     */
    private function product(int $productId, array $dates, string $currency): array
    {
        $rows = $dates === []
            ? collect()
            : DB::table('daily_product_metrics')
                ->where('product_id', $productId)
                ->whereIn('day', $dates)
                ->orderBy('day')
                ->get();

        $byDay = ['views' => [], 'units_sold' => [], 'gross_minor' => [], 'cart_adds' => []];
        $totals = ['views' => 0, 'units_sold' => 0, 'gross_minor' => 0, 'cart_adds' => 0, 'purchases' => 0];

        foreach ($rows as $row) {
            $day = (string) $row->day;

            foreach (array_keys($byDay) as $column) {
                $value = (int) $row->{$column};
                $byDay[$column][$day] = $value;
                $totals[$column] += $value;
            }

            $totals['purchases'] += (int) $row->purchases;
        }

        $labels = [
            'views' => 'Views',
            'units_sold' => 'Units sold',
            'gross_minor' => 'Gross',
            'cart_adds' => 'Cart adds',
        ];

        return [
            'productId' => $productId,
            'totals' => [
                'views' => $totals['views'],
                'cartAdds' => $totals['cart_adds'],
                'unitsSold' => $totals['units_sold'],
                'purchases' => $totals['purchases'],
                'grossMinor' => $totals['gross_minor'],
                'gross' => Money::of($totals['gross_minor'], $currency)->format(),
                'conversionRate' => $totals['views'] === 0
                    ? null
                    : round($totals['purchases'] / $totals['views'] * 100, 2),
            ],
            'series' => array_map(
                static fn (string $column): array => MetricSeries::fill(
                    key: $column,
                    label: $labels[$column],
                    days: $dates,
                    byDay: $byDay[$column],
                    isMoney: $column === 'gross_minor',
                )->toArray(),
                array_keys($byDay),
            ),
        ];
    }
}
