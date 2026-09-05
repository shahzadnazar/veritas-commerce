<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Actions;

use App\Modules\Reviews\Data\ReviewActor;
use App\Modules\Reviews\Events\ReviewEdited;
use App\Modules\Reviews\Exceptions\ReviewRefused;
use App\Modules\Reviews\Models\ProductReview;
use App\Modules\Reviews\Support\ReviewText;
use Illuminate\Support\Facades\DB;

/**
 * A customer changes their own review. §9.
 *
 * Only the author, and only the rating and the words. The product, the
 * purchase it rests on and the verified flag are immutable on the model,
 * so an edit cannot quietly turn a review of one thing into a review of
 * another — which is what would let somebody buy a cheap item, take
 * delivery, and re-point the verified badge at something they never
 * bought.
 *
 * A rating change moves the aggregate, so the summary is recomputed in the
 * same transaction.
 *
 * The status is not touched. A hidden review stays hidden while its author
 * improves it, and a moderator decides whether that changed anything —
 * editing is not a way back into public view.
 */
final class EditReview
{
    public function __construct(private readonly RecomputeRatingSummary $recompute) {}

    public function __invoke(
        ProductReview $review,
        int $userId,
        int $rating,
        string $body,
        ?string $title = null,
        ?ReviewActor $actor = null,
    ): ProductReview {
        $actor ??= ReviewActor::customer($userId);

        if ((int) $review->user_id !== $userId) {
            throw ReviewRefused::notTheAuthor();
        }

        if ($rating < 1 || $rating > 5) {
            throw ReviewRefused::ratingOutOfRange($rating);
        }

        $cleanBody = ReviewText::body($body);

        if (! ReviewText::isUsableBody($cleanBody)) {
            throw ReviewRefused::bodyTooShort();
        }

        DB::transaction(function () use ($review, $rating, $cleanBody, $title): void {
            /** @var ProductReview $locked */
            $locked = ProductReview::query()->whereKey($review->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->isEditableByAuthor()) {
                throw ReviewRefused::notEditable($locked->status);
            }

            $locked->forceFill([
                'rating' => $rating,
                'title' => ReviewText::title($title),
                'body' => $cleanBody,
            ])->save();

            ($this->recompute)((int) $locked->product_id);
        });

        $fresh = $review->refresh();

        DB::afterCommit(function () use ($fresh): void {
            event(new ReviewEdited(
                reviewId: $fresh->id,
                reviewPublicId: $fresh->public_id,
                productId: $fresh->product_id,
                userId: $fresh->user_id,
                rating: $fresh->rating,
                verifiedPurchase: $fresh->verified_purchase,
            ));
        });

        return $fresh;
    }
}
