<?php

declare(strict_types=1);

namespace App\Modules\Recommendations\Data;

use App\Modules\Recommendations\Enums\RecommendationSlot;

/**
 * Everything a strategy is allowed to know about who is asking.
 *
 * Deliberately narrow. A strategy gets a slot, an anchor, an identity and
 * a limit — not a request, not a session, not a user model. That is what
 * makes a strategy testable without a browser, and what stops one reaching
 * for something it should not have.
 */
final readonly class RecommendationRequest
{
    /**
     * @param  array<int, int>  $excludeProductIds  ids the caller already shows elsewhere
     * @param  array<int, int>  $basketProductIds  what is already in the cart
     */
    public function __construct(
        public RecommendationSlot $slot,
        public ?int $anchorProductId = null,
        public ?int $userId = null,
        public ?string $anonymousSessionId = null,
        public int $limit = 12,
        public array $excludeProductIds = [],
        public ?int $categoryId = null,
        public array $basketProductIds = [],
    ) {}

    /**
     * Every product this request is anchored on.
     *
     * A product page anchors on one thing; a cart anchors on everything in
     * it. Strategies that mine pairs take this rather than the single
     * anchor, so "what completes this basket" ranks partners of the whole
     * basket instead of partners of whichever line happened to be first.
     *
     * @return array<int, int>
     */
    public function anchors(): array
    {
        $anchors = $this->basketProductIds;

        if ($this->anchorProductId !== null) {
            array_unshift($anchors, $this->anchorProductId);
        }

        return array_values(array_unique(array_map(intval(...), $anchors)));
    }

    /** Whether there is anybody to personalise for. */
    public function hasVisitor(): bool
    {
        return $this->userId !== null || $this->anonymousSessionId !== null;
    }

    /**
     * The anchor plus anything the caller excluded.
     *
     * A product is never its own recommendation, and no strategy has to
     * remember that — the service subtracts this set from every candidate
     * list before it is returned.
     *
     * @return array<int, int>
     */
    public function excluded(): array
    {
        $excluded = [...$this->excludeProductIds, ...$this->basketProductIds];

        if ($this->anchorProductId !== null) {
            $excluded[] = $this->anchorProductId;
        }

        return array_values(array_unique(array_map(intval(...), $excluded)));
    }

    /**
     * How many candidates a strategy should fetch.
     *
     * Wider than the limit because eligibility is applied afterwards: a
     * strategy that returns exactly `limit` ids will return fewer than
     * `limit` products the moment one of them is unpublished. Capped so a
     * pathological limit cannot turn into an unbounded scan.
     */
    public function candidateLimit(): int
    {
        return min(200, max(12, $this->limit * 4));
    }

    /** @param  array<int, int>  $ids */
    public function excluding(array $ids): self
    {
        return new self(
            slot: $this->slot,
            anchorProductId: $this->anchorProductId,
            userId: $this->userId,
            anonymousSessionId: $this->anonymousSessionId,
            limit: $this->limit,
            excludeProductIds: array_values(array_unique([...$this->excludeProductIds, ...$ids])),
            categoryId: $this->categoryId,
            basketProductIds: $this->basketProductIds,
        );
    }
}
