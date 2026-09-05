<?php

declare(strict_types=1);

namespace App\Modules\AdminPortal\Http\Controllers;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Reviews\Actions\ModerateReview;
use App\Modules\Reviews\Data\ReviewActor;
use App\Modules\Reviews\Enums\ReviewStatus;
use App\Modules\Reviews\Exceptions\ReviewRefused;
use App\Modules\Reviews\Models\ProductReview;
use App\Modules\Reviews\Queries\BuildModerationQueue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Review moderation.
 *
 * §8: this is not an approval queue. A verified review publishes the
 * moment it is written, and an admin arrives afterwards to decide that
 * something should not have been. Requiring approval for every honest
 * review would delay all of them by a working day to catch the rare
 * abusive one — and a marketplace whose reviews appear a day late is one
 * whose reviews nobody trusts to be current.
 *
 * The write side is three named actions, not a status dropdown. Hiding,
 * rejecting and restoring are different decisions with different meanings,
 * and each carries a reason recorded on the review and in the audit log.
 * A free-form status setter would let an operator put a review into a
 * state the domain has no route to, and the next person to read it would
 * have no way of knowing how it got there.
 *
 * Every one of the three recomputes the product's rating inside the same
 * transaction as the status change (see ModerateReview), so there is never
 * a window in which the page shows a rating that counts a review nobody
 * can read.
 */
final class AdminReviewController
{
    public function __construct(
        private readonly BuildModerationQueue $queue,
        private readonly ModerateReview $moderate,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string', 'max:32'],
            'search' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $status = isset($filters['status']) ? (string) $filters['status'] : null;
        $search = isset($filters['search']) ? (string) $filters['search'] : null;

        return Inertia::render('Reviews/Index', [
            'reviews' => ($this->queue)($status, $search, (int) ($filters['page'] ?? 1)),
            'counts' => $this->queue->counts(),
            'filters' => [
                'status' => $status,
                'search' => $search,
            ],
            'statuses' => array_map(
                static fn (ReviewStatus $case): array => [
                    'value' => $case->value,
                    'label' => $case->label(),
                    'requiresReason' => $case->requiresReason(),
                ],
                ReviewStatus::cases(),
            ),
        ]);
    }

    /** Take a review off the page. Reversible, and the author is told why. */
    public function hide(Request $request, string $review): RedirectResponse
    {
        return $this->apply($request, $review, 'hide', reasonRequired: true);
    }

    /**
     * Refuse a review outright.
     *
     * Distinct from hiding: a hidden review is one the marketplace has
     * parked, a rejected one is a decision that it breaks the rules. The
     * author sees a different message, and the two are counted separately
     * — collapsing them would make "how much abuse do we get" unanswerable.
     */
    public function reject(Request $request, string $review): RedirectResponse
    {
        return $this->apply($request, $review, 'reject', reasonRequired: true);
    }

    /** Put a review back. A note is optional; a moderator can be wrong. */
    public function restore(Request $request, string $review): RedirectResponse
    {
        return $this->apply($request, $review, 'restore', reasonRequired: false);
    }

    private function apply(
        Request $request,
        string $publicId,
        string $decision,
        bool $reasonRequired,
    ): RedirectResponse {
        $validated = $request->validate([
            'reason' => [$reasonRequired ? 'required' : 'nullable', 'string', 'min:3', 'max:500'],
        ]);

        $reason = isset($validated['reason']) ? (string) $validated['reason'] : null;

        /** @var ProductReview|null $review */
        $review = ProductReview::query()->where('public_id', $publicId)->first();

        abort_if($review === null, 404);

        $admin = $request->user('admin');
        $adminId = $admin === null ? null : (int) $admin->getAuthIdentifier();
        $actor = ReviewActor::admin($adminId);
        $from = $review->status;

        try {
            $changed = match ($decision) {
                'hide' => $this->moderate->hide($review, $actor, (string) $reason),
                'reject' => $this->moderate->reject($review, $actor, (string) $reason),
                default => $this->moderate->restore($review, $actor, $reason),
            };
        } catch (ReviewRefused $refusal) {
            throw ValidationException::withMessages(['reason' => $refusal->getMessage()]);
        }

        if ($changed) {
            ($this->audit)(
                action: 'review.'.$decision,
                actorType: 'admin',
                actorId: $adminId,
                subjectType: 'product_review',
                subjectId: (int) $review->id,
                changes: ['from' => $from->value, 'to' => $review->refresh()->status->value],
                reason: $reason,
            );
        }

        return back()->with('status', $changed
            ? 'The review has been updated.'
            : 'That review was already in this state.');
    }
}
