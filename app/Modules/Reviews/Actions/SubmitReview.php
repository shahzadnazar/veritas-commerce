<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Actions;

use App\Modules\Reviews\Data\ReviewActor;
use App\Modules\Reviews\Enums\ReviewStatus;
use App\Modules\Reviews\Events\ReviewPublished;
use App\Modules\Reviews\Exceptions\ReviewRefused;
use App\Modules\Reviews\Models\ProductReview;
use App\Modules\Reviews\Models\ProductReviewEvent;
use App\Modules\Reviews\Queries\ReviewEligibility;
use App\Modules\Reviews\Support\ReviewText;
use Illuminate\Support\Facades\DB;

/**
 * A customer writes a review.
 *
 * NOTE WHAT THIS METHOD DOES NOT TAKE: a `verified_purchase` flag, an
 * order item id, a seller order id, or a status. Every one of those is
 * derived here from evidence the server found itself (§4). A request body
 * can claim anything it likes and there is no parameter for it to land in
 * — which is a stronger guarantee than validating the claim, because
 * validation can be forgotten and an absent parameter cannot.
 *
 * The rating is checked in three places on purpose: here for a message
 * worth reading, in the form request for the shape, and by a CHECK
 * constraint in PostgreSQL for the case where neither ran. Only the last
 * one is the control.
 *
 * §8: a verified review publishes immediately. Holding an honest review
 * for a human to read is how a marketplace ends up with no reviews, and
 * moderation after the fact is both cheaper and recorded.
 */
final class SubmitReview
{
    public function __construct(
        private readonly ReviewEligibility $eligibility,
        private readonly RecomputeRatingSummary $recompute,
    ) {}

    public function __invoke(
        int $userId,
        int $productId,
        int $rating,
        string $body,
        ?string $title = null,
        ?ReviewActor $actor = null,
    ): ProductReview {
        $actor ??= ReviewActor::customer($userId);

        if ($rating < 1 || $rating > 5) {
            throw ReviewRefused::ratingOutOfRange($rating);
        }

        // Cleaned before it is measured: a body of nothing but a script
        // tag is empty once stripped, and should be refused as too short
        // rather than stored as a blank review.
        $cleanBody = ReviewText::body($body);

        if (! ReviewText::isUsableBody($cleanBody)) {
            throw ReviewRefused::bodyTooShort();
        }

        $review = DB::transaction(function () use (
            $userId, $productId, $rating, $cleanBody, $title, $actor
        ): ProductReview {
            $evidence = ($this->eligibility)($userId, $productId);

            if (! $evidence->mayReview) {
                throw ReviewRefused::notEligible($evidence);
            }

            $review = ProductReview::query()->create([
                'product_id' => $productId,
                'user_id' => $userId,
                // The purchase this rests on, so a moderator can check the
                // claim rather than trust the flag.
                'order_item_id' => $evidence->orderItemId,
                'seller_order_id' => $evidence->sellerOrderId,
                'rating' => $rating,
                'title' => ReviewText::title($title),
                'body' => $cleanBody,
                'status' => ReviewStatus::Published->value,
                'verified_purchase' => $evidence->verifiedPurchase,
                'published_at' => now(),
            ]);

            ProductReviewEvent::query()->create([
                'product_review_id' => $review->id,
                'from_status' => null,
                'to_status' => ReviewStatus::Published->value,
                'actor_type' => $actor->type,
                'actor_id' => $actor->id,
                'actor_label' => $actor->label,
                'created_at' => now(),
            ]);

            // In the same transaction: the rating a visitor sees and the
            // rating in the JSON-LD beside it come from this row, and a
            // job that had not run yet would make them disagree (§16).
            ($this->recompute)($productId);

            return $review;
        });

        DB::afterCommit(function () use ($review): void {
            event(new ReviewPublished(
                reviewId: $review->id,
                reviewPublicId: $review->public_id,
                productId: $review->product_id,
                userId: $review->user_id,
                rating: $review->rating,
                verifiedPurchase: $review->verified_purchase,
            ));
        });

        return $review;
    }
}
