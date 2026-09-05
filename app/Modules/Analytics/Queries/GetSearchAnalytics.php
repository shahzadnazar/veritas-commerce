<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Queries;

use App\Modules\Analytics\Enums\AnalyticsPeriod;
use App\Modules\Analytics\Support\AnalyticsDay;
use Illuminate\Support\Facades\DB;

/**
 * What people are looking for, and what they are not finding.
 *
 * The second list is the one worth building this for. A phrase people
 * search repeatedly and that returns nothing is either a product the
 * marketplace should stock or a synonym the index should know — a
 * decision somebody can act on, unlike a chart of total search volume.
 *
 * Every figure is read from daily_search_metrics, so this page costs one
 * indexed query and not an aggregate over the whole event log.
 */
final class GetSearchAnalytics
{
    private const LIST_SIZE = 25;

    /** @return array<string, mixed> */
    public function __invoke(AnalyticsPeriod $period): array
    {
        $dates = array_map(
            static fn (AnalyticsDay $day): string => $day->date,
            $period->dayRange(),
        );

        return [
            'period' => ['value' => $period->value, 'label' => $period->label()],
            'periods' => AnalyticsPeriod::options(),
            'timezone' => AnalyticsDay::timezone(),
            'from' => $dates === [] ? null : $dates[0],
            'to' => $dates === [] ? null : $dates[count($dates) - 1],
            'totals' => $this->totals($dates),
            'topPhrases' => $this->phrases($dates, zeroResultsOnly: false),
            'zeroResultPhrases' => $this->phrases($dates, zeroResultsOnly: true),
        ];
    }

    /**
     * @param  array<int, string>  $dates
     * @return array<string, mixed>
     */
    private function totals(array $dates): array
    {
        if ($dates === []) {
            return ['searches' => 0, 'zeroResults' => 0, 'clicks' => 0, 'clickRate' => null, 'zeroResultRate' => null];
        }

        $row = DB::table('daily_search_metrics')
            ->whereIn('day', $dates)
            ->selectRaw(
                'coalesce(sum(searches), 0) as searches, '.
                'coalesce(sum(zero_result_searches), 0) as zero_results, '.
                'coalesce(sum(clicks), 0) as clicks, '.
                'coalesce(sum(purchases), 0) as purchases'
            )
            ->first();

        $searches = $row === null ? 0 : (int) $row->searches;
        $zeroResults = $row === null ? 0 : (int) $row->zero_results;
        $clicks = $row === null ? 0 : (int) $row->clicks;

        return [
            'searches' => $searches,
            'zeroResults' => $zeroResults,
            'clicks' => $clicks,
            'purchases' => $row === null ? 0 : (int) $row->purchases,
            // Null rather than zero on an empty period: "nobody searched"
            // and "everybody searched and nobody clicked" are different
            // problems, and a 0% that means the first is a lie.
            'clickRate' => $searches === 0 ? null : round($clicks / $searches * 100, 1),
            'zeroResultRate' => $searches === 0 ? null : round($zeroResults / $searches * 100, 1),
        ];
    }

    /**
     * @param  array<int, string>  $dates
     * @return array<int, array<string, mixed>>
     */
    private function phrases(array $dates, bool $zeroResultsOnly): array
    {
        if ($dates === []) {
            return [];
        }

        $query = DB::table('daily_search_metrics')
            ->whereIn('day', $dates)
            ->groupBy('query_normalised')
            ->selectRaw(
                'query_normalised, sum(searches) as searches, sum(sessions) as sessions, '.
                'sum(zero_result_searches) as zero_results, sum(clicks) as clicks, '.
                'sum(cart_adds) as cart_adds, sum(purchases) as purchases'
            );

        if ($zeroResultsOnly) {
            // Only phrases that found nothing *every* time. A phrase that
            // usually works and failed once is a transient, not a gap in
            // the catalogue, and mixing the two makes the list unusable.
            $query->havingRaw('sum(zero_result_searches) = sum(searches)')
                ->havingRaw('sum(searches) > 0')
                ->orderByRaw('sum(searches) desc');
        } else {
            $query->orderByRaw('sum(searches) desc');
        }

        return $query
            ->orderBy('query_normalised')
            ->limit(self::LIST_SIZE)
            ->get()
            ->map(static function ($row): array {
                $searches = (int) $row->searches;
                $clicks = (int) $row->clicks;

                return [
                    'phrase' => (string) $row->query_normalised,
                    'searches' => $searches,
                    'sessions' => (int) $row->sessions,
                    'zeroResults' => (int) $row->zero_results,
                    'clicks' => $clicks,
                    'cartAdds' => (int) $row->cart_adds,
                    'purchases' => (int) $row->purchases,
                    'clickRate' => $searches === 0 ? null : round($clicks / $searches * 100, 1),
                ];
            })
            ->all();
    }
}
