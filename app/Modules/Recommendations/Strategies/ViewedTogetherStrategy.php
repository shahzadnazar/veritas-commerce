<?php

declare(strict_types=1);

namespace App\Modules\Recommendations\Strategies;

use App\Modules\Recommendations\Contracts\RecommendationStrategy;
use App\Modules\Recommendations\Data\RecommendationRequest;
use App\Modules\Recommendations\Enums\AssociationKind;
use App\Modules\Recommendations\Queries\GetProductAssociations;

/**
 * What other people looked at in the same visit.
 *
 * Weaker evidence than a shared order and far more plentiful, which is the
 * trade this strategy exists to make. The support threshold is what keeps
 * it honest: two products that appeared in one session together are a
 * coincidence, and below the threshold this returns nothing rather than
 * dressing a coincidence up as a pattern.
 */
final class ViewedTogetherStrategy implements RecommendationStrategy
{
    public function __construct(private readonly GetProductAssociations $associations) {}

    public function key(): string
    {
        return 'viewed_together';
    }

    public function supports(RecommendationRequest $request): bool
    {
        return $request->anchors() !== [];
    }

    /** @return array<int, int> */
    public function candidates(RecommendationRequest $request): array
    {
        $anchors = $request->anchors();

        if ($anchors === []) {
            return [];
        }

        return $this->associations->forMany(
            $anchors,
            AssociationKind::ViewedTogether,
            $request->candidateLimit(),
        );
    }
}
