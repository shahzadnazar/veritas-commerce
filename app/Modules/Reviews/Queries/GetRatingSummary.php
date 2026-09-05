<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Queries;

use App\Modules\Reviews\Data\RatingSummaryView;
use App\Modules\Reviews\Models\ProductRatingSummary;

/**
 * A product's rating, for whoever is showing it.
 *
 * Deliberately a Query returning a Data object rather than something the
 * catalogue imports a Model to read. Catalog already owns products and
 * Reviews already reads products; a second edge in the other direction
 * through models would make the two mutually dependent, and the page can
 * simply ask for the number.
 *
 * One indexed read of a precomputed row. The whole reason
 * `product_rating_summaries` exists is that a product page must not
 * aggregate every review on every request (§15).
 */
final class GetRatingSummary
{
    public function __invoke(int $productId): RatingSummaryView
    {
        /** @var ProductRatingSummary|null $summary */
        $summary = ProductRatingSummary::query()->where('product_id', $productId)->first();

        return RatingSummaryView::from($summary);
    }

    /**
     * Ratings for many products at once, for a grid of cards.
     *
     * One query for the page rather than one per card — a category
     * listing shows twenty-four products and §76 rules out a lookup each.
     *
     * @param  array<int, int>  $productIds
     * @return array<int, RatingSummaryView> keyed by product id
     */
    public function forProducts(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $summaries = ProductRatingSummary::query()
            ->whereIn('product_id', $productIds)
            ->get()
            ->keyBy('product_id');

        $out = [];

        foreach ($productIds as $id) {
            /** @var ProductRatingSummary|null $summary */
            $summary = $summaries->get($id);

            $out[$id] = RatingSummaryView::from($summary);
        }

        return $out;
    }
}
