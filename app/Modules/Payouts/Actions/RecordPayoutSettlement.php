<?php

declare(strict_types=1);

namespace App\Modules\Payouts\Actions;

use App\Modules\Ledger\Actions\PostLedgerEntry;
use App\Modules\Ledger\Enums\LedgerEntryStatus;
use App\Modules\Ledger\Enums\LedgerEntryType;
use App\Modules\Payouts\Data\PayoutActor;
use App\Modules\Payouts\Enums\PayoutStatus;
use App\Modules\Payouts\Enums\SettlementAttemptStatus;
use App\Modules\Payouts\Events\PayoutPaid;
use App\Modules\Payouts\Events\SellerBalanceNegative;
use App\Modules\Payouts\Exceptions\PayoutNotPermitted;
use App\Modules\Payouts\Models\PayoutRequest;
use App\Modules\Payouts\Models\PayoutSettlementAttempt;
use App\Modules\Payouts\Queries\GetSellerFinancialPosition;
use App\Modules\Sellers\Models\SellerAccount;
use Illuminate\Support\Facades\DB;

/**
 * The money actually left. Write it down. §27.
 *
 * Phase 1 settlement happens outside the platform: somebody in finance
 * makes a transfer and comes here to record what they did. So this action
 * does NOT move money — it records that money was moved, and makes the
 * ledger say so.
 *
 * The three things that must happen together, in this order, in one
 * transaction:
 *
 *   1. a settlement attempt is written, with the reference the transfer
 *      was made under. A partial unique index allows one successful
 *      attempt per payout, which is the database's answer to two admins
 *      pressing the button at once (§53).
 *   2. ONE payout debit is appended to the seller ledger, negative, keyed
 *      by `payout:{id}` so a replay finds it rather than posting a second.
 *   3. the reservation SETTLES and the request becomes PAID.
 *
 * Step 3 is where §29 lives. Closing the hold and posting the debit look
 * like two subtractions and are not: a settled allocation stops reserving
 * at the same moment the debit starts reducing the available position, so
 * a seller with $500 available and a $400 payout ends with $100
 * withdrawable — not with -$300, which is what happens if the hold is left
 * standing, and not with $500, which is what happens if the debit is
 * skipped. The invariant suite proves the exact figures.
 *
 * The original earnings are untouched throughout. §28: a $500 earning that
 * funded a $400 payout is still a $500 earning, with a -$400 row beside
 * it. Ledger history is not edited to reflect its consequences.
 */
final class RecordPayoutSettlement
{
    public function __construct(
        private readonly AdvancePayout $advance,
        private readonly ReleasePayoutReservation $reservation,
        private readonly PostLedgerEntry $ledger,
        private readonly GetSellerFinancialPosition $position,
    ) {}

    /**
     * @param  string  $method  how the transfer was made — wire, ach, paypal
     * @param  string  $externalReference  what it can be found by at the bank
     * @return bool whether this call was the one that settled it
     */
    public function __invoke(
        PayoutRequest $request,
        PayoutActor $actor,
        string $method,
        string $externalReference,
        ?string $note = null,
    ): bool {
        $method = trim($method);
        $externalReference = trim($externalReference);

        /*
         * §27 requires a reference, and the requirement is real rather
         * than ceremonial: it is the only link between this row and the
         * money that moved, and a settlement without one cannot be
         * reconciled against a bank statement by anybody, ever.
         */
        if ($externalReference === '' || $method === '') {
            throw PayoutNotPermitted::settlementReferenceRequired();
        }

        $settled = DB::transaction(function () use ($request, $actor, $method, $externalReference, $note): bool {
            /** @var PayoutRequest $locked */
            $locked = PayoutRequest::query()
                ->withoutGlobalScopes()
                ->whereKey($request->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // Already paid. A replayed click, or the loser of a race with
            // another admin: the work is done and nothing is repeated.
            if ($locked->status === PayoutStatus::Paid) {
                return false;
            }

            if (! $locked->status->isSettleable()) {
                throw PayoutNotPermitted::notSettleable($locked->status);
            }

            $held = $this->reservation->heldMinor($locked->id);

            if ($held !== $locked->amount_minor) {
                throw PayoutNotPermitted::exceedsWithdrawable($locked->amount_minor, $held, $locked->currency);
            }

            PayoutSettlementAttempt::query()->create([
                'payout_request_id' => $locked->id,
                'provider' => 'manual',
                'method' => $method,
                'external_reference' => $externalReference,
                'status' => SettlementAttemptStatus::Succeeded->value,
                'currency' => $locked->currency,
                'amount_minor' => $locked->amount_minor,
                'initiated_at' => now(),
                'completed_at' => now(),
                'initiated_by_admin_id' => $actor->isAdmin() ? $actor->id : null,
            ]);

            /** @var SellerAccount $seller */
            $seller = SellerAccount::query()->whereKey($locked->seller_account_id)->firstOrFail();

            /*
             * The debit. Negative, status PAID, and keyed so it can only
             * exist once — PostLedgerEntry returns the existing row rather
             * than inserting a second when the key is taken.
             */
            ($this->ledger)(
                seller: $seller,
                type: LedgerEntryType::Payout,
                amountMinor: -$locked->amount_minor,
                status: LedgerEntryStatus::Paid,
                payoutRequestId: $locked->id,
                note: "Payout {$locked->reference}",
                currency: $locked->currency,
                sourceKey: "payout:{$locked->id}",
            );

            // The hold ends because the debit replaced it, not as well as.
            $this->reservation->settle($locked->id);

            $locked->forceFill([
                'paid_at' => now(),
                'settled_by_admin_id' => $actor->isAdmin() ? $actor->id : null,
                'settlement_ref' => $externalReference,
                'settlement_method' => $method,
            ])->save();

            return ($this->advance)($locked, PayoutStatus::Paid, $actor, $note);
        });

        if ($settled) {
            DB::afterCommit(function () use ($request, $actor, $method, $externalReference): void {
                $fresh = $request->refresh();

                event(new PayoutPaid(
                    payoutRequestId: $fresh->id,
                    reference: $fresh->reference,
                    sellerAccountId: (int) $fresh->seller_account_id,
                    sellerName: (string) $fresh->seller_name_snapshot,
                    amountMinor: $fresh->amount_minor,
                    currency: $fresh->currency,
                    settlementReference: $externalReference,
                    method: $method,
                    paidAt: (string) $fresh->paid_at?->toIso8601String(),
                    settledByAdminId: $actor->id,
                ));

                /*
                 * A payout should not itself make a seller negative — the
                 * amount was checked against withdrawable twice — but if
                 * something upstream has gone wrong, finance finds out
                 * from an event rather than from the seller.
                 */
                $position = ($this->position)((int) $fresh->seller_account_id, $fresh->currency);

                if ($position->isNegative()) {
                    event(new SellerBalanceNegative(
                        payoutRequestId: $fresh->id,
                        reference: $fresh->reference,
                        sellerAccountId: (int) $fresh->seller_account_id,
                        sellerName: (string) $fresh->seller_name_snapshot,
                        amountMinor: $fresh->amount_minor,
                        currency: $fresh->currency,
                        netBalanceMinor: $position->netBalanceMinor(),
                    ));
                }
            });
        }

        return $settled;
    }
}
