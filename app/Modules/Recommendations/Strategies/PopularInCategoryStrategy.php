<?php

declare(strict_types=1);

namespace App\Modules\Recommendations\Strategies;

use App\Modules\Recommendations\Contracts\RecommendationStrategy;
use App\Modules\Recommendations\Data\RecommendationRequest;
use App\Modules\Recommendations\Queries\GetPopularProducts;
use App\Modules\Recommendations\Support\AnchorProfile;

/**
 * What sells in this corner of the catalogue.
 *
 * Sits between the co-occurrence strategies and the global fallback: it
 * needs behaviour, but only enough to rank a category rather than enough
 * to pair two specific products, so it warms up long before "bought
 * together" does.
 */
final class PopularInCategoryStrategy implements RecommendationStrategy
{
    public function __construct(private readonly GetPopularProducts $popular) {}

    public function key(): string
    {
        return 'popular_in_category';
    }

    public function supports(RecommendationRequest $request): bool
    {
        return $request->anchorProductId !== null || $request->categoryId !== null;
    }

    /** @return array<int, int> */
    public function candidates(RecommendationRequest $request): array
    {
        $categoryIds = $this->categoryIds($request);

        if ($categoryIds === []) {
            return [];
        }

        return ($this->popular)(
            GetPopularProducts::defaultWindow(),
            $request->candidateLimit(),
            $categoryIds,
        );
    }

    /** @return array<int, int> */
    private function categoryIds(RecommendationRequest $request): array
    {
        if ($request->categoryId !== null) {
            return [$request->categoryId];
        }

        if ($request->anchorProductId === null) {
            return [];
        }

        $anchor = AnchorProfile::for($request->anchorProductId);

        if ($anchor === null) {
            return [];
        }

        // The direct category only, not the whole lineage: "popular in
        // Electronics" on a phone-case page is not a recommendation, it is
        // a list of televisions.
        return $anchor->categoryId === null ? [] : [$anchor->categoryId];
    }
}
