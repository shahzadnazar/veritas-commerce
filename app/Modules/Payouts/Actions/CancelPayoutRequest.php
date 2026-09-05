<?php

declare(strict_types=1);

namespace App\Modules\Payouts\Actions;

use App\Modules\Payouts\Data\PayoutActor;
use App\Modules\Payouts\Enums\PayoutStatus;
use App\Modules\Payouts\Events\PayoutCancelled;
use App\Modules\Payouts\Exceptions\PayoutNotPermitted;
use App\Modules\Payouts\Models\PayoutRequest;
use Illuminate\Support\Facades\DB;

/**
 * The seller withdraws their own request. §26.
 *
 * THE POLICY, decided and enforced rather than left to a screen:
 *
 *   REQUESTED             the seller may cancel. Nobody has acted on it.
 *   UNDER_REVIEW          they may not. Somebody is working on it, and a
 *                         request vanishing mid-review is how finance
 *                         loses track of what they were doing.
 *   APPROVED / PROCESSING they may not. The money may already be moving,
 *                         and only finance knows whether it has.
 *
 * A seller who needs an approved payout stopped asks support, who reject
 * it — which is a decision with a reason attached, not a disappearance.
 *
 * The request row is never deleted (§26). It moves to CANCELLED, keeps its
 * reference and its history, and its allocations are released rather than
 * removed. A seller looking at their statement can still see the $600 they
 * asked for on the 4th and changed their mind about on the 5th.
 *
 * An admin may also cancel — the actor is passed in — which is the path
 * out of a FAILED settlement that is not going to be retried.
 */
final class CancelPayoutRequest
{
    public function __construct(
        private readonly AdvancePayout $advance,
        private readonly ReleasePayoutReservation $reservation,
    ) {}

    /** @return bool whether this call was the one that cancelled it */
    public function __invoke(PayoutRequest $request, PayoutActor $actor, ?string $reason = null): bool
    {
        $moved = DB::transaction(function () use ($request, $actor, $reason): bool {
            /** @var PayoutRequest $locked */
            $locked = PayoutRequest::query()
                ->withoutGlobalScopes()
                ->whereKey($request->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // The seller's own limit. An admin cancelling a failed
            // settlement is a different act and is bounded by the state
            // machine instead.
            if (! $actor->isAdmin() && ! $locked->status->isCancellableBySeller()) {
                throw PayoutNotPermitted::notCancellable($locked->status);
            }

            if (! ($this->advance)($locked, PayoutStatus::Cancelled, $actor, $reason)) {
                return false;
            }

            $this->reservation->release($locked->id);

            return true;
        });

        if ($moved) {
            DB::afterCommit(function () use ($request, $actor): void {
                $fresh = $request->refresh();

                event(new PayoutCancelled(
                    payoutRequestId: $fresh->id,
                    reference: $fresh->reference,
                    sellerAccountId: (int) $fresh->seller_account_id,
                    sellerName: (string) $fresh->seller_name_snapshot,
                    amountMinor: $fresh->amount_minor,
                    currency: $fresh->currency,
                    cancelledByUserId: $actor->isAdmin() ? null : $actor->id,
                ));
            });
        }

        return $moved;
    }
}
