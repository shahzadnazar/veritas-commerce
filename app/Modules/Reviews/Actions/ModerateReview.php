<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Actions;

use App\Modules\Reviews\Data\ReviewActor;
use App\Modules\Reviews\Enums\ReviewStatus;
use App\Modules\Reviews\Events\ReviewHidden;
use App\Modules\Reviews\Events\ReviewRejected;
use App\Modules\Reviews\Events\ReviewRestored;
use App\Modules\Reviews\Events\ReviewWithdrawn;
use App\Modules\Reviews\Models\ProductReview;
use Illuminate\Support\Facades\DB;

/**
 * Every decision that changes whether a review is public.
 *
 * Four named methods rather than one that takes a status (§11): a
 * moderation screen with a free "set status" dropdown is a screen where
 * the rules live in whoever is using it. Hiding, rejecting, restoring and
 * withdrawing are four different acts with four different consequences,
 * and the one that belongs to the customer is not on the admin's screen at
 * all.
 *
 * WHAT MAKES §3 HOLD: the rating summary is recomputed inside the same
 * transaction as the status change, every time. The moment a moderator
 * hides a review, the visible rating and the JSON-LD both stop counting
 * it — there is no window in which a hidden review is still contributing
 * because a queued job has not run.
 *
 * NEVER EDITS THE CUSTOMER'S WORDS. §9: a moderator decides whether a
 * review is shown, not what it says. There is no parameter here for the
 * body, and the model refuses to have its product or purchase reassigned.
 */
final class ModerateReview
{
    public function __construct(
        private readonly AdvanceReview $advance,
        private readonly RecomputeRatingSummary $recompute,
    ) {}

    /** Out of public view, reversibly, with a reason the customer reads. */
    public function hide(ProductReview $review, ReviewActor $actor, string $reason): bool
    {
        return $this->move($review, ReviewStatus::Hidden, $actor, $reason, ReviewHidden::class);
    }

    /** Refused. Still occupies the customer's slot for this product. */
    public function reject(ProductReview $review, ReviewActor $actor, string $reason): bool
    {
        return $this->move($review, ReviewStatus::Rejected, $actor, $reason, ReviewRejected::class);
    }

    /** Back into public view, and back into the rating. */
    public function restore(ProductReview $review, ReviewActor $actor, ?string $note = null): bool
    {
        return $this->move($review, ReviewStatus::Published, $actor, $note, ReviewRestored::class);
    }

    /**
     * The customer takes their own review down.
     *
     * Separate from the three above because it is the customer's decision
     * rather than the platform's, and because it is the one that frees
     * their slot to write another. The row survives (§10) — the words stop
     * being public and stop counting, and the history of them remains.
     */
    public function withdraw(ProductReview $review, ReviewActor $actor): bool
    {
        return $this->move($review, ReviewStatus::Withdrawn, $actor, null, ReviewWithdrawn::class);
    }

    /**
     * @param  class-string  $eventClass
     */
    private function move(
        ProductReview $review,
        ReviewStatus $to,
        ReviewActor $actor,
        ?string $reason,
        string $eventClass,
    ): bool {
        $moved = DB::transaction(function () use ($review, $to, $actor, $reason): bool {
            /** @var ProductReview $locked */
            $locked = ProductReview::query()->whereKey($review->getKey())->lockForUpdate()->firstOrFail();

            if (! ($this->advance)($locked, $to, $actor, $reason)) {
                return false;
            }

            // Same transaction as the status change. See the note above.
            ($this->recompute)((int) $locked->product_id);

            return true;
        });

        if ($moved) {
            $fresh = $review->refresh();

            DB::afterCommit(function () use ($fresh, $eventClass, $reason): void {
                event(new $eventClass(
                    reviewId: $fresh->id,
                    reviewPublicId: $fresh->public_id,
                    productId: $fresh->product_id,
                    userId: $fresh->user_id,
                    rating: $fresh->rating,
                    verifiedPurchase: $fresh->verified_purchase,
                    reason: $reason,
                ));
            });
        }

        return $moved;
    }
}
