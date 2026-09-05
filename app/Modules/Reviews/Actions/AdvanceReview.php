<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Actions;

use App\Modules\Reviews\Data\ReviewActor;
use App\Modules\Reviews\Enums\ReviewStatus;
use App\Modules\Reviews\Exceptions\ReviewRefused;
use App\Modules\Reviews\Models\ProductReview;
use App\Modules\Reviews\Models\ProductReviewEvent;

/**
 * The only way a review changes state.
 *
 * The state machine is on the enum; this makes it binding. Every hide,
 * reject, restore and withdrawal comes through here, so an illegal move —
 * a withdrawn review being republished by an admin, a rejection with no
 * reason — fails on the server rather than depending on a screen not
 * offering the button.
 *
 * Deliberately not a transaction of its own: the caller has already locked
 * the review and will recompute the rating in the same one. A status
 * change that committed without its summary would leave the visible rating
 * and the structured data disagreeing, which is exactly what §16 forbids.
 *
 * Repeating a transition is not an error and not a second history row.
 */
final class AdvanceReview
{
    /** @return bool whether this call was the one that moved it */
    public function __invoke(
        ProductReview $locked,
        ReviewStatus $to,
        ReviewActor $actor,
        ?string $reason = null,
    ): bool {
        $from = $locked->status;

        if ($from === $to) {
            return false;
        }

        if (! in_array($to, $from->allowedTransitions(), true)) {
            throw ReviewRefused::invalidTransition($from, $to);
        }

        // The customer is told what this says, so it has to say something.
        if ($to->requiresReason() && ($reason === null || trim($reason) === '')) {
            throw ReviewRefused::reasonRequired($to);
        }

        $locked->forceFill(array_merge(
            ['status' => $to->value],
            $this->stampsFor($to, $actor, $reason),
        ))->save();

        ProductReviewEvent::query()->create([
            'product_review_id' => $locked->id,
            'from_status' => $from->value,
            'to_status' => $to->value,
            'actor_type' => $actor->type,
            'actor_id' => $actor->id,
            'actor_label' => $actor->label,
            'reason' => $reason,
            'created_at' => now(),
        ]);

        return true;
    }

    /**
     * The columns that belong to each state, in one map so a status and
     * its date cannot disagree.
     *
     * Restoring clears the moderation reason: the review is published
     * again, and leaving "spam" attached to a review a moderator decided
     * was fine would be a note nobody meant to keep.
     *
     * @return array<string, mixed>
     */
    private function stampsFor(ReviewStatus $to, ReviewActor $actor, ?string $reason): array
    {
        $adminId = $actor->isAdmin() ? $actor->id : null;

        return match ($to) {
            ReviewStatus::Published => [
                'published_at' => now(),
                'hidden_at' => null,
                'rejected_at' => null,
                'moderation_reason' => null,
                'moderated_by_admin_id' => $adminId,
            ],
            ReviewStatus::Hidden => [
                'hidden_at' => now(),
                'moderation_reason' => $reason,
                'moderated_by_admin_id' => $adminId,
            ],
            ReviewStatus::Rejected => [
                'rejected_at' => now(),
                'moderation_reason' => $reason,
                'moderated_by_admin_id' => $adminId,
            ],
            // The customer's own doing, so no moderator is recorded
            // against it and no moderation reason is invented.
            ReviewStatus::Withdrawn => ['withdrawn_at' => now()],
        };
    }
}
