<?php

declare(strict_types=1);

namespace App\Modules\Recommendations\Contracts;

use App\Modules\Recommendations\Data\RecommendationRequest;

/**
 * One way of choosing candidate products.
 *
 * A strategy returns *ids*, ranked, and nothing else. It does not check
 * whether a product is published, does not build a card, does not know
 * what a shelf looks like and does not decide whether its answer is good
 * enough — RecommendationService and EligibleRecommendationProducts own
 * all four. Keeping the interface this narrow is what makes it safe to add
 * a strategy later: the worst a bad one can do is return ids nobody may
 * see, and those are dropped at the gate.
 *
 * §44: rule-based, every one of them. A strategy is a SQL query over data
 * the marketplace already has. No model is trained, no vector is embedded
 * and no service is called.
 */
interface RecommendationStrategy
{
    /**
     * A stable identifier, recorded on the set so "why is this here" has
     * an answer.
     */
    public function key(): string;

    /** Whether this strategy can say anything at all about this request. */
    public function supports(RecommendationRequest $request): bool;

    /**
     * Candidate product ids, best first.
     *
     * May return fewer than asked for, or none at all — that is the normal
     * case for a cold marketplace, and the reason there is a fallback
     * chain. Must be deterministic for a given database state: two calls
     * with the same request return the same order, so a page refresh does
     * not reshuffle a shelf.
     *
     * @return array<int, int>
     */
    public function candidates(RecommendationRequest $request): array;
}
