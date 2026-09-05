<?php

declare(strict_types=1);

namespace App\Modules\Payouts\Actions;

use App\Modules\Payouts\Data\PayoutActor;
use App\Modules\Payouts\Enums\PayoutStatus;
use App\Modules\Payouts\Enums\SettlementAttemptStatus;
use App\Modules\Payouts\Events\PayoutFailed;
use App\Modules\Payouts\Exceptions\PayoutNotPermitted;
use App\Modules\Payouts\Models\PayoutRequest;
use App\Modules\Payouts\Models\PayoutSettlementAttempt;
use Illuminate\Support\Facades\DB;

/**
 * A settlement attempt did not work. §30, with the policy chosen.
 *
 * THE POLICY: A FAILED SETTLEMENT KEEPS THE RESERVATION.
 *
 * The alternative — release on failure — reads as tidier and is wrong. A
 * manual transfer that bounced is retried far more often than it is
 * abandoned, and money handed back the moment an attempt failed is money
 * the seller can request again while finance is still chasing the first
 * one. That is how a seller gets paid twice.
 *
 * So FAILED is not terminal for the money. It is a visible exception state
 * that finance clears deliberately: retry (back to PROCESSING, then
 * settle), or end it (reject or cancel), and ending it is what releases
 * the hold. There is no path that quietly returns the request to REQUESTED
 * as though nothing had been attempted — §66 — because the attempt row
 * would then be the only evidence, and nobody would be looking at it.
 *
 * Every attempt keeps its own row with its own reason. Retrying appends;
 * it never overwrites.
 */
final class FailPayoutSettlement
{
    public function __construct(private readonly AdvancePayout $advance) {}

    /** @return bool whether this call was the one that failed it */
    public function __invoke(
        PayoutRequest $request,
        PayoutActor $actor,
        string $reason,
        ?string $failureCode = null,
        ?string $method = null,
        ?string $externalReference = null,
    ): bool {
        if (trim($reason) === '') {
            throw PayoutNotPermitted::reasonRequired('fail');
        }

        $failed = DB::transaction(function () use ($request, $actor, $reason, $failureCode, $method, $externalReference): bool {
            /** @var PayoutRequest $locked */
            $locked = PayoutRequest::query()
                ->withoutGlobalScopes()
                ->whereKey($request->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->status->isSettleable()) {
                throw PayoutNotPermitted::notSettleable($locked->status);
            }

            PayoutSettlementAttempt::query()->create([
                'payout_request_id' => $locked->id,
                'provider' => 'manual',
                'method' => $method,
                'external_reference' => $externalReference,
                'status' => SettlementAttemptStatus::Failed->value,
                'currency' => $locked->currency,
                'amount_minor' => $locked->amount_minor,
                'initiated_at' => now(),
                'completed_at' => now(),
                'failure_code' => $failureCode,
                'failure_message' => mb_substr($reason, 0, 500),
                'initiated_by_admin_id' => $actor->isAdmin() ? $actor->id : null,
            ]);

            // The allocations are deliberately left HELD. See above.
            return ($this->advance)($locked, PayoutStatus::Failed, $actor, $reason);
        });

        if ($failed) {
            DB::afterCommit(function () use ($request, $reason, $failureCode): void {
                $fresh = $request->refresh();

                event(new PayoutFailed(
                    payoutRequestId: $fresh->id,
                    reference: $fresh->reference,
                    sellerAccountId: (int) $fresh->seller_account_id,
                    sellerName: (string) $fresh->seller_name_snapshot,
                    amountMinor: $fresh->amount_minor,
                    currency: $fresh->currency,
                    reason: $reason,
                    failureCode: $failureCode,
                ));
            });
        }

        return $failed;
    }
}
