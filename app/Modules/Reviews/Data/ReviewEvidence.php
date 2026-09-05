<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Data;

use App\Modules\Reviews\Enums\ReviewIneligibility;

/**
 * What the server found when it asked whether this customer may review
 * this product.
 *
 * The shape is the guarantee. There is no constructor a caller can use to
 * assert `verified` — the two named constructors are the only ways to make
 * one, and `verified()` is reachable only from ReviewEligibility, which
 * built it from an order line it found itself. A controller cannot
 * manufacture this object with a flag from a request body, because the
 * object has nowhere to put one.
 */
final readonly class ReviewEvidence
{
    private function __construct(
        public bool $mayReview,
        public bool $verifiedPurchase,
        public ?int $orderItemId,
        public ?int $sellerOrderId,
        public ?ReviewIneligibility $reason,
        public ?int $existingReviewId,
    ) {}

    public static function verified(int $orderItemId, int $sellerOrderId): self
    {
        return new self(
            mayReview: true,
            verifiedPurchase: true,
            orderItemId: $orderItemId,
            sellerOrderId: $sellerOrderId,
            reason: null,
            existingReviewId: null,
        );
    }

    public static function refused(ReviewIneligibility $reason, ?int $existingReviewId = null): self
    {
        return new self(
            mayReview: false,
            verifiedPurchase: false,
            orderItemId: null,
            sellerOrderId: null,
            reason: $reason,
            existingReviewId: $existingReviewId,
        );
    }

    /** The words the customer reads. The enum name never reaches them. */
    public function message(): ?string
    {
        return $this->reason?->message();
    }

    /**
     * What the screen is told. Note what is absent: the order item id and
     * the seller order id stay on the server (§5), because a customer's
     * own order line is not something a review form needs to carry.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'canReview' => $this->mayReview,
            'reason' => $this->reason?->value,
            'message' => $this->message(),
            'hasExistingReview' => $this->existingReviewId !== null,
        ];
    }
}
