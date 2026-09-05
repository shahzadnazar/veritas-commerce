<?php

declare(strict_types=1);

namespace App\Modules\Recommendations\Strategies;

use App\Modules\Recommendations\Contracts\RecommendationStrategy;
use App\Modules\Recommendations\Data\RecommendationRequest;
use App\Modules\Recommendations\Queries\GetPopularProducts;

/**
 * What the whole marketplace is looking at.
 *
 * Impersonal by design, and therefore cacheable across visitors — which is
 * what makes it a cheap fallback for a shelf whose better strategies came
 * back empty.
 */
final class TrendingStrategy implements RecommendationStrategy
{
    public function __construct(private readonly GetPopularProducts $popular) {}

    public function key(): string
    {
        return 'trending';
    }

    public function supports(RecommendationRequest $request): bool
    {
        return true;
    }

    /** @return array<int, int> */
    public function candidates(RecommendationRequest $request): array
    {
        $windows = GetPopularProducts::windows();
        $shortest = min($windows);

        $trending = ($this->popular)($shortest, $request->candidateLimit());

        if ($trending !== []) {
            return $trending;
        }

        // A quiet week is not a reason to show nothing: fall back to the
        // longest window before giving up on behaviour entirely.
        return ($this->popular)(max($windows), $request->candidateLimit());
    }
}
