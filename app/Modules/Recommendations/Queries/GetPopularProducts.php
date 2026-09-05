<?php

declare(strict_types=1);

namespace App\Modules\Recommendations\Queries;

use Illuminate\Support\Facades\DB;

/**
 * Reads the popularity projection.
 *
 * Popularity is precomputed per product per window, so a home page costs
 * one indexed read rather than an aggregate over the whole event log.
 * §29: no controller counts events.
 */
final class GetPopularProducts
{
    /**
     * The most popular products in a window, optionally within a category
     * lineage.
     *
     * @param  array<int, int>  $categoryIds  the anchor category and its descendants
     * @return array<int, int>
     */
    public function __invoke(int $windowDays, int $limit, array $categoryIds = []): array
    {
        if ($limit < 1) {
            return [];
        }

        $query = DB::table('product_popularity_scores as s')
            ->where('s.window_days', $windowDays)
            ->where('s.score', '>', 0);

        if ($categoryIds !== []) {
            $query->join('product_search_documents as d', 'd.product_id', '=', 's.product_id')
                ->whereIn('d.category_id', array_values(array_unique(array_map(intval(...), $categoryIds))));
        }

        return $query
            ->orderByDesc('s.score')
            ->orderBy('s.product_id')
            ->limit($limit)
            ->pluck('s.product_id')
            ->map(intval(...))
            ->all();
    }

    /** The window a caller gets when it does not care which. */
    public static function defaultWindow(): int
    {
        $windows = config('veritas.recommendations.windows');

        if (! is_array($windows) || $windows === []) {
            return 30;
        }

        $first = reset($windows);

        return is_numeric($first) ? (int) $first : 30;
    }

    /**
     * The windows the projection is built over.
     *
     * Never empty: a misconfigured list falls back to the defaults rather
     * than silently rebuilding nothing, which would leave every popularity
     * strategy permanently cold with no error to explain why.
     *
     * @return non-empty-list<int>
     */
    public static function windows(): array
    {
        $configured = config('veritas.recommendations.windows');

        if (! is_array($configured)) {
            return [7, 30];
        }

        $valid = [];

        foreach ($configured as $value) {
            $days = is_numeric($value) ? (int) $value : 0;

            if ($days > 0 && ! in_array($days, $valid, true)) {
                $valid[] = $days;
            }
        }

        if ($valid === []) {
            return [7, 30];
        }

        return $valid;
    }
}
