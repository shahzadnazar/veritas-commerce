<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Events;

/**
 * A customer's review went live.
 *
 * Dispatched after the transaction commits. Carries what a listener needs
 * so nothing downstream has to read the review back — and no listener
 * changes a rating: the summary is recomputed inside the action, before
 * the commit that dispatched this, because a rating that lagged a job
 * would be a visible number disagreeing with the structured data beside it.
 */
final readonly class ReviewPublished
{
    public function __construct(
        public int $reviewId,
        public string $reviewPublicId,
        public int $productId,
        public int $userId,
        public int $rating,
        public bool $verifiedPurchase,
        public ?string $reason = null,
    ) {}
}
