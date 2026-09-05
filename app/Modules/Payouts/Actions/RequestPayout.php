<?php

declare(strict_types=1);

namespace App\Modules\Payouts\Actions;

use App\Modules\Orders\Actions\AllocateReference;
use App\Modules\Payouts\Data\PayoutActor;
use App\Modules\Payouts\Enums\PayoutAccountType;
use App\Modules\Payouts\Enums\PayoutStatus;
use App\Modules\Payouts\Events\PayoutRequested;
use App\Modules\Payouts\Exceptions\PayoutNotPermitted;
use App\Modules\Payouts\Models\PayoutAccount;
use App\Modules\Payouts\Models\PayoutRequest;
use App\Modules\Payouts\Models\PayoutStatusHistory;
use App\Modules\Payouts\Queries\EvaluatePayoutEligibility;
use App\Modules\Payouts\Queries\GetSellerFinancialPosition;
use App\Modules\Payouts\Support\PayoutPolicy;
use App\Modules\Sellers\Concerns\CurrentSeller;
use App\Modules\Sellers\Models\SellerAccount;
use Illuminate\Support\Facades\DB;

/**
 * A seller asks to withdraw part of their money, and it is held.
 *
 * §9, in order. Everything that decides anything happens on the server:
 * the seller is the one the caller is authenticated as, the balance is
 * read from the ledger, the maximum is computed here, and the amount is
 * the only thing taken from the request — validated against a figure the
 * browser never supplied.
 *
 * THE LOCK ORDER, which every financial action in this module follows and
 * which §55 asks to be written down once:
 *
 *     1. the seller account row       (the seller's financial scope)
 *     2. the payout request row       (if one already exists)
 *     3. ledger and allocation writes
 *
 * Taking the seller's row first is what makes two simultaneous requests
 * safe. Both want the same row; one waits; the second reads a balance that
 * already reflects the first one's hold and is refused. A refund
 * finalizing at the same moment takes the same row for the same reason, so
 * "seller withdraws while a refund lands" resolves to one order or the
 * other rather than to both reading the same balance.
 *
 * The database's partial unique index on open requests is the backstop,
 * not the control — but it is a real backstop, and the invariant suite
 * proves it holds with the application check bypassed entirely.
 */
final class RequestPayout
{
    public function __construct(
        private readonly GetSellerFinancialPosition $position,
        private readonly EvaluatePayoutEligibility $eligibility,
        private readonly AllocatePayoutFunds $allocate,
        private readonly AllocateReference $references,
    ) {}

    /**
     * @param  int  $amountMinor  what the seller asked for, in minor units
     * @param  bool  $actorMayRequest  whether the caller holds payouts.request
     */
    public function __invoke(
        SellerAccount $seller,
        int $amountMinor,
        ?string $currency = null,
        ?PayoutActor $actor = null,
        bool $actorMayRequest = true,
        ?int $payoutAccountId = null,
    ): PayoutRequest {
        $currency = strtoupper($currency ?? PayoutPolicy::currency());
        $actor ??= PayoutActor::seller(null);

        /*
         * The amount is checked for shape before anything is locked. A
         * zero or negative request is not a race to lose, it is a bad
         * request, and holding a row while deciding that helps nobody.
         */
        if ($amountMinor <= 0) {
            throw PayoutNotPermitted::notPositive($amountMinor, $currency);
        }

        $request = CurrentSeller::actingAs($seller->id, fn (): PayoutRequest => DB::transaction(
            function () use ($seller, $amountMinor, $currency, $actor, $actorMayRequest, $payoutAccountId): PayoutRequest {
                /** @var SellerAccount $lockedSeller */
                $lockedSeller = SellerAccount::query()
                    ->whereKey($seller->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                // Read once, under the lock, and reused for both the
                // eligibility decision and the amount check — so the two
                // cannot be answered from different balances.
                $position = ($this->position)($lockedSeller->id, $currency);

                $eligibility = ($this->eligibility)($lockedSeller, $currency, $actorMayRequest, $position);

                if (! $eligibility->canRequest) {
                    throw PayoutNotPermitted::ineligible($eligibility);
                }

                $withdrawable = $position->withdrawableMinor();

                if ($amountMinor > $withdrawable) {
                    throw PayoutNotPermitted::exceedsWithdrawable($amountMinor, $withdrawable, $currency);
                }

                $minimum = PayoutPolicy::minimumMinor();

                if ($amountMinor < $minimum) {
                    throw PayoutNotPermitted::belowMinimum($amountMinor, $minimum, $currency);
                }

                $destination = $this->resolveDestination($lockedSeller->id, $currency, $payoutAccountId);

                $payout = PayoutRequest::query()->create([
                    'reference' => $this->references->payoutReference(),
                    'seller_account_id' => $lockedSeller->id,
                    'payout_account_id' => $destination?->id,
                    // Snapshots. §57: read months later, this record still
                    // says where the money was going and who asked.
                    'destination_label' => $destination?->snapshotLabel(),
                    'destination_type' => ($destination === null ? PayoutAccountType::Manual : $destination->type)->value,
                    'seller_name_snapshot' => $lockedSeller->legal_name,
                    'currency' => $currency,
                    'amount_minor' => $amountMinor,
                    'status' => PayoutStatus::Requested->value,
                    'requested_at' => now(),
                    'requested_by_user_id' => $actor->type === 'seller' ? $actor->id : null,
                ]);

                // The hold. From this line the money is out of
                // withdrawable, and it is out because these rows exist —
                // not because a column was decremented somewhere.
                ($this->allocate)($payout, $amountMinor);

                PayoutStatusHistory::query()->create([
                    'payout_request_id' => $payout->id,
                    'from_status' => null,
                    'to_status' => PayoutStatus::Requested->value,
                    'actor_type' => $actor->type,
                    'actor_id' => $actor->id,
                    'actor_label' => $actor->label,
                    'created_at' => now(),
                ]);

                return $payout;
            }
        ));

        DB::afterCommit(function () use ($request, $seller, $actor): void {
            event(new PayoutRequested(
                payoutRequestId: $request->id,
                reference: $request->reference,
                sellerAccountId: $seller->id,
                sellerName: (string) $request->seller_name_snapshot,
                amountMinor: $request->amount_minor,
                currency: $request->currency,
                requestedByUserId: $actor->type === 'seller' ? $actor->id : null,
                destinationLabel: $request->destinationLabel(),
            ));
        });

        return $request;
    }

    /**
     * Which destination this payout is for.
     *
     * An explicit id must belong to this seller — §77 — and is looked up
     * scoped to them, so another seller's account id resolves to nothing
     * rather than to their bank details.
     */
    private function resolveDestination(int $sellerAccountId, string $currency, ?int $payoutAccountId): ?PayoutAccount
    {
        $query = PayoutAccount::query()
            ->withoutGlobalScopes()
            ->where('seller_account_id', $sellerAccountId)
            ->where('status', PayoutAccount::STATUS_ACTIVE)
            ->where('currency', $currency);

        if ($payoutAccountId !== null) {
            $query->whereKey($payoutAccountId);
        }

        /** @var PayoutAccount|null $account */
        $account = $query->first();

        return $account;
    }
}
