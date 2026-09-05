<?php

declare(strict_types=1);

namespace App\Modules\Payouts\Actions;

use App\Modules\Payouts\Data\PayoutActor;
use App\Modules\Payouts\Enums\PayoutStatus;
use App\Modules\Payouts\Events\PayoutApproved;
use App\Modules\Payouts\Exceptions\PayoutNotPermitted;
use App\Modules\Payouts\Models\PayoutRequest;
use App\Modules\Payouts\Support\PayoutPolicy;
use Illuminate\Support\Facades\DB;

/**
 * Finance authorises the payout. §24.
 *
 * APPROVED MEANS AUTHORISED FOR SETTLEMENT. IT DOES NOT MEAN PAID.
 *
 * That distinction is the whole reason this is a separate action from
 * RecordPayoutSettlement, and it is worth being blunt about, because the
 * failure it prevents is a seller being told their money is on the way
 * when nobody has sent it. Nothing financial changes here: no ledger entry
 * is written, no reservation is closed, the balance is exactly what it was
 * a moment ago. The money is still held — it has been held since the
 * request — and it stays held until it is either sent or given back.
 *
 * What is validated is that the money is still there. Between the request
 * and the approval a refund may have landed, and approving a payout the
 * seller can no longer fund would leave finance sending money against a
 * negative balance. So the reservation is re-checked against the request's
 * own amount under the lock.
 */
final class ApprovePayout
{
    public function __construct(
        private readonly AdvancePayout $advance,
        private readonly ReleasePayoutReservation $reservation,
    ) {}

    /** @return bool whether this call was the one that approved it */
    public function __invoke(PayoutRequest $request, PayoutActor $actor, ?string $note = null): bool
    {
        $moved = DB::transaction(function () use ($request, $actor, $note): bool {
            /** @var PayoutRequest $locked */
            $locked = PayoutRequest::query()
                ->withoutGlobalScopes()
                ->whereKey($request->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! PayoutPolicy::supports($locked->currency)) {
                throw PayoutNotPermitted::currencyMismatch(
                    implode('/', PayoutPolicy::supportedCurrencies()),
                    $locked->currency,
                );
            }

            /*
             * The reservation must still cover the request. It is held in
             * allocation rows rather than a column, so this is a real
             * check of real money and not a restatement of the amount.
             */
            $held = $this->reservation->heldMinor($locked->id);

            if ($held !== $locked->amount_minor) {
                throw PayoutNotPermitted::exceedsWithdrawable($locked->amount_minor, $held, $locked->currency);
            }

            return ($this->advance)($locked, PayoutStatus::Approved, $actor, $note);
        });

        if ($moved) {
            DB::afterCommit(function () use ($request, $actor): void {
                $fresh = $request->refresh();

                event(new PayoutApproved(
                    payoutRequestId: $fresh->id,
                    reference: $fresh->reference,
                    sellerAccountId: (int) $fresh->seller_account_id,
                    sellerName: (string) $fresh->seller_name_snapshot,
                    amountMinor: $fresh->amount_minor,
                    currency: $fresh->currency,
                    approvedByAdminId: $actor->id,
                ));
            });
        }

        return $moved;
    }
}
