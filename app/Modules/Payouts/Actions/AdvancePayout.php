<?php

declare(strict_types=1);

namespace App\Modules\Payouts\Actions;

use App\Modules\Payouts\Data\PayoutActor;
use App\Modules\Payouts\Enums\PayoutStatus;
use App\Modules\Payouts\Exceptions\PayoutNotPermitted;
use App\Modules\Payouts\Models\PayoutRequest;
use App\Modules\Payouts\Models\PayoutStatusHistory;

/**
 * The only way a payout request changes state.
 *
 * Every caller has already locked the request row — this is deliberately
 * not a transaction of its own, because the state change and the money it
 * implies must commit or fail together. Approving a payout and releasing
 * its reservation in two transactions is how you get a rejected request
 * still holding money.
 *
 * Repeating a transition is not an error: two finance admins pressing
 * "Approve" on the same request within a second of each other should
 * produce one approval, one history row and one notification, and the
 * second call finds the work done and says so by returning false. §52.
 *
 * The timestamps live in one map beside the transition, so a status and
 * its date cannot disagree.
 */
final class AdvancePayout
{
    /** @return bool whether this call was the one that moved it */
    public function __invoke(
        PayoutRequest $locked,
        PayoutStatus $to,
        PayoutActor $actor,
        ?string $reason = null,
    ): bool {
        $from = $locked->status;

        if ($from === $to) {
            return false;
        }

        if (! in_array($to, $from->allowedTransitions(), true)) {
            throw PayoutNotPermitted::invalidTransition($from, $to);
        }

        // §25 and §42: what the seller is told must be recorded, so a
        // rejection without a reason is refused rather than silently
        // producing an empty explanation on their screen.
        if ($to->requiresReason() && ($reason === null || trim($reason) === '')) {
            throw PayoutNotPermitted::reasonRequired($to === PayoutStatus::Rejected ? 'reject' : 'fail');
        }

        $locked->forceFill(array_merge(
            ['status' => $to->value],
            $this->stampsFor($to, $actor, $reason),
        ))->save();

        PayoutStatusHistory::query()->create([
            'payout_request_id' => $locked->id,
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
     * The columns that belong to each state.
     *
     * `decided_at` and `decided_by_admin_id` are the M0 columns and are
     * kept current for every terminal decision, so anything written
     * against them still works; the state-specific columns beside them are
     * what a four-eyes policy would read.
     *
     * @return array<string, mixed>
     */
    private function stampsFor(PayoutStatus $to, PayoutActor $actor, ?string $reason): array
    {
        $adminId = $actor->isAdmin() ? $actor->id : null;

        return match ($to) {
            PayoutStatus::UnderReview => [
                'reviewed_at' => now(),
                'reviewed_by_admin_id' => $adminId,
            ],
            PayoutStatus::Approved => [
                'approved_at' => now(),
                'approved_by_admin_id' => $adminId,
                'decided_at' => now(),
                'decided_by_admin_id' => $adminId,
                'decision_reason' => $reason,
            ],
            PayoutStatus::Rejected => [
                'decided_at' => now(),
                'decided_by_admin_id' => $adminId,
                'decision_reason' => $reason,
            ],
            PayoutStatus::Cancelled => [
                'cancelled_at' => now(),
                'decided_at' => now(),
                'decided_by_admin_id' => $adminId,
                'decision_reason' => $reason,
            ],
            // paid_at and the settlement columns are written by
            // RecordPayoutSettlement, which knows the reference; setting a
            // paid date here without one would claim money moved with no
            // record of how.
            PayoutStatus::Paid => [],
            PayoutStatus::Failed => [
                'failed_at' => now(),
                'decision_reason' => $reason,
            ],
            PayoutStatus::Requested, PayoutStatus::Processing => [],
        };
    }
}
