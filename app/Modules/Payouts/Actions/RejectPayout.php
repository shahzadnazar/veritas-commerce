<?php

declare(strict_types=1);

namespace App\Modules\Payouts\Actions;

use App\Modules\Payouts\Data\PayoutActor;
use App\Modules\Payouts\Enums\PayoutStatus;
use App\Modules\Payouts\Events\PayoutRejected;
use App\Modules\Payouts\Models\PayoutRequest;
use Illuminate\Support\Facades\DB;

/**
 * Finance refuses the payout and the money goes back. §25.
 *
 * A reason is mandatory — enforced in AdvancePayout, because it is a
 * property of the transition rather than of this caller — and it is shown
 * to the seller verbatim, so it is written for them rather than for an
 * internal ticket.
 *
 * Three things must happen together or not at all: the status moves, the
 * allocations release, and the history records why. They share this one
 * transaction, so a rejection can never leave money held against a request
 * that has been refused.
 *
 * No payout debit is written. Nothing left the platform, so nothing is
 * recorded as having left it — that is the difference between a rejection
 * and a settlement, and it is why the ledger stays reconcilable.
 *
 * Idempotent. A double-clicked reject moves the request once and releases
 * once: the second call finds a terminal state and the release's
 * conditional UPDATE matches no held rows anyway (§45).
 */
final class RejectPayout
{
    public function __construct(
        private readonly AdvancePayout $advance,
        private readonly ReleasePayoutReservation $reservation,
    ) {}

    /** @return bool whether this call was the one that rejected it */
    public function __invoke(PayoutRequest $request, PayoutActor $actor, string $reason): bool
    {
        $moved = DB::transaction(function () use ($request, $actor, $reason): bool {
            /** @var PayoutRequest $locked */
            $locked = PayoutRequest::query()
                ->withoutGlobalScopes()
                ->whereKey($request->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! ($this->advance)($locked, PayoutStatus::Rejected, $actor, $reason)) {
                return false;
            }

            $this->reservation->release($locked->id);

            return true;
        });

        if ($moved) {
            DB::afterCommit(function () use ($request, $actor, $reason): void {
                $fresh = $request->refresh();

                event(new PayoutRejected(
                    payoutRequestId: $fresh->id,
                    reference: $fresh->reference,
                    sellerAccountId: (int) $fresh->seller_account_id,
                    sellerName: (string) $fresh->seller_name_snapshot,
                    amountMinor: $fresh->amount_minor,
                    currency: $fresh->currency,
                    reason: $reason,
                    decidedByAdminId: $actor->id,
                ));
            });
        }

        return $moved;
    }
}
