<?php

declare(strict_types=1);

namespace App\Modules\Payouts\Actions;

use App\Modules\Payouts\Data\PayoutActor;
use App\Modules\Payouts\Enums\PayoutStatus;
use App\Modules\Payouts\Events\PayoutSettlementStarted;
use App\Modules\Payouts\Models\PayoutRequest;
use Illuminate\Support\Facades\DB;

/**
 * Finance is about to try settling again. §66.
 *
 * Moves APPROVED or FAILED to PROCESSING, which is what makes "somebody is
 * currently sending this" visible on the queue rather than being a state
 * that exists only in a person's head. The previous attempt keeps its row
 * and its reason; nothing is overwritten and nothing goes back to
 * REQUESTED.
 *
 * The reservation is untouched — it has been held since the request and
 * stays held through every attempt, which is the whole point of the policy
 * in FailPayoutSettlement.
 */
final class RetryPayoutSettlement
{
    public function __construct(private readonly AdvancePayout $advance) {}

    /** @return bool whether this call was the one that opened the attempt */
    public function __invoke(PayoutRequest $request, PayoutActor $actor, string $method = 'manual'): bool
    {
        $moved = DB::transaction(function () use ($request, $actor): bool {
            /** @var PayoutRequest $locked */
            $locked = PayoutRequest::query()
                ->withoutGlobalScopes()
                ->whereKey($request->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            return ($this->advance)($locked, PayoutStatus::Processing, $actor);
        });

        if ($moved) {
            DB::afterCommit(function () use ($request, $method): void {
                $fresh = $request->refresh();

                event(new PayoutSettlementStarted(
                    payoutRequestId: $fresh->id,
                    reference: $fresh->reference,
                    sellerAccountId: (int) $fresh->seller_account_id,
                    sellerName: (string) $fresh->seller_name_snapshot,
                    amountMinor: $fresh->amount_minor,
                    currency: $fresh->currency,
                    method: $method,
                ));
            });
        }

        return $moved;
    }
}
