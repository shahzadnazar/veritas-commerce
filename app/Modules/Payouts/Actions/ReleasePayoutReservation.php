<?php

declare(strict_types=1);

namespace App\Modules\Payouts\Actions;

use App\Modules\Payouts\Enums\PayoutAllocationStatus;
use App\Modules\Payouts\Models\PayoutAllocation;
use Illuminate\Support\Carbon;

/**
 * Gives a payout's held money back, or marks it spent.
 *
 * Two callers, two outcomes, and they are the same operation because the
 * hold ends either way:
 *
 *   RELEASED — the request was rejected or cancelled. The money returns to
 *              withdrawable and no debit is posted.
 *   SETTLED  — the payout was paid. The hold ends because the ledger debit
 *              has taken its place. §29: closing the hold and posting the
 *              debit must not both reduce the balance, and they do not,
 *              because a settled allocation stops reserving while the
 *              debit starts subtracting.
 *
 * A conditional UPDATE narrowed to `status = held` is the concurrency
 * guard. Two admins rejecting the same request both narrow to the same
 * rows and the second matches nothing, so a reservation cannot be released
 * twice (§45) and cannot be released at all once it has settled.
 *
 * MUST run inside the caller's transaction, under the payout's row lock.
 */
final class ReleasePayoutReservation
{
    /** @return int how many allocations this call ended */
    public function release(int $payoutRequestId): int
    {
        return $this->close($payoutRequestId, [
            'status' => PayoutAllocationStatus::Released->value,
            'released_at' => now(),
        ]);
    }

    /** @return int how many allocations this call settled */
    public function settle(int $payoutRequestId): int
    {
        return $this->close($payoutRequestId, [
            'status' => PayoutAllocationStatus::Settled->value,
            'settled_at' => now(),
        ]);
    }

    /** The amount currently held by this request, for validation before closing. */
    public function heldMinor(int $payoutRequestId): int
    {
        return (int) PayoutAllocation::query()
            ->withoutGlobalScopes()
            ->where('payout_request_id', $payoutRequestId)
            ->where('status', PayoutAllocationStatus::Held->value)
            ->sum('amount_minor');
    }

    /** @param array{status: string, settled_at?: Carbon, released_at?: Carbon} $values */
    private function close(int $payoutRequestId, array $values): int
    {
        return PayoutAllocation::query()
            ->withoutGlobalScopes()
            ->where('payout_request_id', $payoutRequestId)
            ->where('status', PayoutAllocationStatus::Held->value)
            ->update($values);
    }
}
