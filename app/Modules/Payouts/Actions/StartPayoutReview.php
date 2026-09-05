<?php

declare(strict_types=1);

namespace App\Modules\Payouts\Actions;

use App\Modules\Payouts\Data\PayoutActor;
use App\Modules\Payouts\Enums\PayoutStatus;
use App\Modules\Payouts\Events\PayoutReviewStarted;
use App\Modules\Payouts\Models\PayoutRequest;
use Illuminate\Support\Facades\DB;

/**
 * Finance picks up a request. §23.
 *
 * Nothing financial moves: the reservation was taken when the seller
 * asked, and review is a person reading. What it does is put a name and a
 * time against the request, so "who looked at this" is answerable and two
 * admins do not both start on the same one without noticing.
 *
 * Repeating it is not an error. A reviewer refreshing the page, or a
 * second admin arriving a moment later, finds it already under review and
 * gets one history row between them.
 */
final class StartPayoutReview
{
    public function __construct(private readonly AdvancePayout $advance) {}

    /** @return bool whether this call was the one that opened the review */
    public function __invoke(PayoutRequest $request, PayoutActor $actor): bool
    {
        $moved = DB::transaction(function () use ($request, $actor): bool {
            /** @var PayoutRequest $locked */
            $locked = PayoutRequest::query()
                ->withoutGlobalScopes()
                ->whereKey($request->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            return ($this->advance)($locked, PayoutStatus::UnderReview, $actor);
        });

        if ($moved) {
            DB::afterCommit(function () use ($request, $actor): void {
                $fresh = $request->refresh();

                event(new PayoutReviewStarted(
                    payoutRequestId: $fresh->id,
                    reference: $fresh->reference,
                    sellerAccountId: (int) $fresh->seller_account_id,
                    sellerName: (string) $fresh->seller_name_snapshot,
                    amountMinor: $fresh->amount_minor,
                    currency: $fresh->currency,
                    reviewedByAdminId: $actor->id,
                ));
            });
        }

        return $moved;
    }
}
