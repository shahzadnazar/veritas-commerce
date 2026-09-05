<?php

declare(strict_types=1);

namespace App\Modules\Recommendations\Strategies;

use App\Modules\Recommendations\Contracts\RecommendationStrategy;
use App\Modules\Recommendations\Data\RecommendationRequest;
use App\Modules\Recommendations\Enums\AssociationKind;
use App\Modules\Recommendations\Queries\GetProductAssociations;

/**
 * What other people paid for in the same order.
 *
 * The strongest signal the marketplace has, and the scarcest: it needs
 * paid orders containing two things, so it says nothing at all for most of
 * a young catalogue. That is why it sits at the top of a chain rather than
 * on its own — §39, a shelf that is empty because the best strategy had no
 * data is a shelf that should have shown the second-best one.
 */
final class BoughtTogetherStrategy implements RecommendationStrategy
{
    public function __construct(private readonly GetProductAssociations $associations) {}

    public function key(): string
    {
        return 'bought_together';
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
            AssociationKind::BoughtTogether,
            $request->candidateLimit(),
        );
    }
}
