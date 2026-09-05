<?php

declare(strict_types=1);

namespace Tests\Feature\Payouts;

use App\Modules\Ledger\Enums\LedgerEntryStatus;
use App\Modules\Ledger\Enums\LedgerEntryType;
use App\Modules\Ledger\Models\SellerLedgerEntry;
use App\Modules\Payouts\Actions\ApprovePayout;
use App\Modules\Payouts\Actions\CancelPayoutRequest;
use App\Modules\Payouts\Actions\FailPayoutSettlement;
use App\Modules\Payouts\Actions\RecordPayoutSettlement;
use App\Modules\Payouts\Actions\RejectPayout;
use App\Modules\Payouts\Actions\StartPayoutReview;
use App\Modules\Payouts\Data\PayoutActor;
use App\Modules\Payouts\Enums\PayoutAllocationStatus;
use App\Modules\Payouts\Enums\PayoutStatus;
use App\Modules\Payouts\Exceptions\PayoutNotPermitted;
use App\Modules\Payouts\Models\PayoutAllocation;
use App\Modules\Payouts\Models\PayoutSettlementAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The six properties the M7 brief requires proved before any payout UI
 * exists, plus the arithmetic they rest on.
 *
 *   1. CLEARING money cannot be withdrawn.
 *   2. An open reservation reduces withdrawable immediately.
 *   3. Two concurrent requests cannot reserve the same money.  (in
 *      PayoutConcurrencyTest, which needs two real connections)
 *   4. Paying a payout creates exactly one immutable ledger debit.
 *   5. Closing the reservation and posting the debit do not both subtract.
 *   6. A refund after a payout preserves the payout and can make the
 *      seller's balance negative.
 *
 * Every figure below is asserted exactly. Approximate money is not money.
 */
final class PayoutInvariantsTest extends TestCase
{
    use BuildsSellerFinance;
    use RefreshDatabase;

    private function financeAdmin(): PayoutActor
    {
        return PayoutActor::admin(null, 'Finance');
    }

    // ---------------------------------------------------------------
    // 1. Clearing money cannot be withdrawn
    // ---------------------------------------------------------------

    #[Test]
    public function pending_and_clearing_money_are_not_withdrawable(): void
    {
        ['seller' => $seller] = $this->makeSeller();

        $this->pendingEarning($seller, 30_000);
        $this->clearingEarning($seller, 50_000);

        $position = $this->positionOf($seller);

        $this->assertSame(30_000, $position->pendingMinor);
        $this->assertSame(50_000, $position->clearingMinor);
        $this->assertSame(0, $position->availableMinor);
        $this->assertSame(80_000, $position->netBalanceMinor(), 'Both are still owed to the seller.');
        $this->assertSame(0, $position->withdrawableMinor(), 'Neither can be asked for.');

        $this->destination($seller);

        $this->expectException(PayoutNotPermitted::class);
        $this->requestPayout($seller, 10_000);
    }

    #[Test]
    public function available_money_is_withdrawable_and_a_reversal_reduces_it(): void
    {
        ['seller' => $seller] = $this->makeSeller();

        $this->availableEarning($seller, 90_000);
        $this->reversal($seller, 20_000);

        $position = $this->positionOf($seller);

        $this->assertSame(70_000, $position->availableMinor, 'The reversal nets against the earning.');
        $this->assertSame(70_000, $position->withdrawableMinor());
    }

    #[Test]
    public function withdrawable_is_never_negative_however_short_the_store_is(): void
    {
        /*
         * The definition, asserted directly:
         *
         *   raw payout capacity = min(available, net balance) - reserved
         *   withdrawable        = max(0, raw payout capacity)
         *
         * A position may be below zero; the amount that may be withdrawn
         * from it is nothing rather than a negative number. Every case
         * that can produce a negative capacity is walked here, because a
         * negative "available to withdraw" is the kind of figure that
         * reaches a screen or a `min($requested, $withdrawable)` before
         * anybody notices.
         */
        ['seller' => $seller] = $this->makeSeller();

        // 1. A reversal larger than the available balance.
        $this->availableEarning($seller, 10_000);
        $this->reversal($seller, 25_000);

        $position = $this->positionOf($seller);

        $this->assertSame(-15_000, $position->availableMinor);
        $this->assertSame(-15_000, $position->netBalanceMinor());
        $this->assertSame(-15_000, $position->rawPayoutCapacityMinor());
        $this->assertSame(0, $position->withdrawableMinor(), 'Never below zero.');
        $this->assertTrue($position->isNegative());
        $this->assertTrue($position->isShort());
        $this->assertFalse($position->hasWithdrawableFunds());

        // 2. Clearing money that does not rescue a negative available
        // balance: the cap on `available` still binds.
        $this->clearingEarning($seller, 40_000);

        $capped = $this->positionOf($seller);

        $this->assertSame(25_000, $capped->netBalanceMinor(), 'Owed overall...');
        $this->assertSame(-15_000, $capped->availableMinor, '...but not yet spendable.');
        $this->assertSame(-15_000, $capped->rawPayoutCapacityMinor());
        $this->assertSame(0, $capped->withdrawableMinor());
        $this->assertFalse($capped->isNegative(), 'The platform owes this store money.');
        $this->assertTrue($capped->isShort(), 'And it still cannot withdraw any.');

        // 3. Whatever the shape, the two figures agree on the clamp.
        $this->assertSame(
            max(0, $capped->rawPayoutCapacityMinor()),
            $capped->withdrawableMinor(),
        );

        // 4. And the serialised shape never carries a negative one.
        $serialised = $capped->toArray();

        $this->assertSame(0, $serialised['withdrawableMinor']);
        $this->assertSame('$0.00', $serialised['withdrawable']);
        $this->assertSame(-15_000, $serialised['rawPayoutCapacityMinor']);
        $this->assertSame('-$150.00', $serialised['rawPayoutCapacity']);
        $this->assertSame('$250.00', $serialised['netBalance']);
    }

    #[Test]
    public function a_healthy_store_sees_the_same_number_twice(): void
    {
        // The clamp must do nothing in the ordinary case, or it would be
        // hiding something rather than defining something.
        ['seller' => $seller] = $this->makeSeller();

        $this->availableEarning($seller, 100_000);
        $this->clearingEarning($seller, 30_000);
        $this->destination($seller);

        $this->requestPayout($seller, 40_000);

        $position = $this->positionOf($seller);

        $this->assertSame(60_000, $position->rawPayoutCapacityMinor());
        $this->assertSame(60_000, $position->withdrawableMinor());
        $this->assertFalse($position->isShort());
        $this->assertFalse($position->isNegative());
    }

    // ---------------------------------------------------------------
    // 2. A reservation reduces withdrawable immediately
    // ---------------------------------------------------------------

    #[Test]
    public function an_open_reservation_reduces_withdrawable_without_touching_the_ledger(): void
    {
        ['seller' => $seller] = $this->makeSeller();

        $this->availableEarning($seller, 100_000);
        $this->destination($seller);

        $entriesBefore = SellerLedgerEntry::query()->withoutGlobalScopes()->count();

        $this->requestPayout($seller, 60_000);

        $position = $this->positionOf($seller);

        // §4, exactly: the ledger value is historically unchanged, the
        // reservation is the difference, and the two are separate facts.
        $this->assertSame(100_000, $position->availableMinor);
        $this->assertSame(60_000, $position->reservedMinor);
        $this->assertSame(40_000, $position->withdrawableMinor());

        $this->assertSame(
            $entriesBefore,
            SellerLedgerEntry::query()->withoutGlobalScopes()->count(),
            'A hold is not a ledger entry. Nothing moved.',
        );
    }

    #[Test]
    public function the_reservation_names_the_earnings_it_is_holding(): void
    {
        ['seller' => $seller] = $this->makeSeller();

        $first = $this->availableEarning($seller, 20_000);
        $second = $this->availableEarning($seller, 25_000);
        $this->availableEarning($seller, 5_000);
        $this->destination($seller);

        $request = $this->requestPayout($seller, 40_000);

        $allocations = PayoutAllocation::query()
            ->withoutGlobalScopes()
            ->where('payout_request_id', $request->id)
            ->orderBy('id')
            ->get();

        // Oldest first, and the total is exactly what was asked for. The
        // third earning is untouched, because 20,000 + 20,000 was enough.
        $this->assertSame(
            [
                [(int) $first->id, 20_000],
                [(int) $second->id, 20_000],
            ],
            $allocations->map(
                static fn (PayoutAllocation $held): array => [
                    (int) $held->seller_ledger_entry_id,
                    $held->amount_minor,
                ],
            )->all(),
        );
        $this->assertSame(40_000, (int) $allocations->sum('amount_minor'));
    }

    #[Test]
    public function a_second_request_cannot_reach_the_money_the_first_is_holding(): void
    {
        ['seller' => $seller] = $this->makeSeller();

        $this->availableEarning($seller, 100_000);
        $this->destination($seller);

        $request = $this->requestPayout($seller, 80_000);

        // §50's arithmetic, asserted on its own so it does not rest on
        // the one-open rule happening to refuse the second request first.
        $this->assertSame(100_000, $this->positionOf($seller)->availableMinor);
        $this->assertSame(80_000, $this->positionOf($seller)->reservedMinor);
        $this->assertSame(20_000, $this->positionOf($seller)->withdrawableMinor());

        // And the Phase-1 rule, which refuses it for its own reason.
        try {
            $this->requestPayout($seller, 50_000);
            $this->fail('A second open request must be refused.');
        } catch (PayoutNotPermitted $refused) {
            $this->assertSame('open_payout_exists', $refused->reason);
        }

        // Giving the money back makes it reachable again, and only then.
        app(CancelPayoutRequest::class)($request, PayoutActor::seller(null));

        $this->assertSame(100_000, $this->positionOf($seller)->withdrawableMinor());
        $this->assertSame(50_000, $this->requestPayout($seller, 50_000)->amount_minor);
    }

    // ---------------------------------------------------------------
    // 4 and 5. Settlement posts one debit and does not double-subtract
    // ---------------------------------------------------------------

    #[Test]
    public function settlement_posts_exactly_one_debit_and_does_not_subtract_twice(): void
    {
        ['seller' => $seller] = $this->makeSeller();

        $this->availableEarning($seller, 50_000);
        $this->destination($seller);

        $request = $this->requestPayout($seller, 40_000);

        // Before settlement, §29's first figure set.
        $before = $this->positionOf($seller);
        $this->assertSame(50_000, $before->availableMinor);
        $this->assertSame(40_000, $before->reservedMinor);
        $this->assertSame(10_000, $before->withdrawableMinor());

        app(ApprovePayout::class)($request, $this->financeAdmin());

        // Approval is not payment: nothing has changed financially.
        $afterApproval = $this->positionOf($seller);
        $this->assertSame(40_000, $afterApproval->reservedMinor);
        $this->assertSame(10_000, $afterApproval->withdrawableMinor());
        $this->assertSame(0, $afterApproval->paidOutMinor, 'Approved is not paid.');
        $this->assertSame(
            0,
            SellerLedgerEntry::query()->withoutGlobalScopes()
                ->where('type', LedgerEntryType::Payout->value)->count(),
            'Approval writes no ledger debit.',
        );

        app(RecordPayoutSettlement::class)(
            $request, $this->financeAdmin(), 'wire', 'FT-99001',
        );

        // §29's second figure set, exactly.
        $after = $this->positionOf($seller);
        $this->assertSame(10_000, $after->availableMinor, 'Available is net of the payout.');
        $this->assertSame(0, $after->reservedMinor, 'The hold closed.');
        $this->assertSame(10_000, $after->withdrawableMinor(), 'Subtracted once, not twice.');
        $this->assertSame(40_000, $after->paidOutMinor);

        $this->assertSame(
            1,
            SellerLedgerEntry::query()->withoutGlobalScopes()
                ->where('type', LedgerEntryType::Payout->value)->count(),
            'Exactly one debit.',
        );

        $debit = SellerLedgerEntry::query()->withoutGlobalScopes()
            ->where('type', LedgerEntryType::Payout->value)
            ->firstOrFail();

        $this->assertSame(-40_000, $debit->amount_minor);
        $this->assertSame(LedgerEntryStatus::Paid, $debit->status);
        $this->assertSame("payout:{$request->id}", $debit->source_key);

        // And the earning it came out of is untouched. §28.
        $earning = SellerLedgerEntry::query()->withoutGlobalScopes()
            ->where('type', LedgerEntryType::SaleEarning->value)
            ->firstOrFail();

        $this->assertSame(50_000, $earning->amount_minor);
    }

    #[Test]
    public function settling_twice_is_harmless(): void
    {
        ['seller' => $seller] = $this->makeSeller();

        $this->availableEarning($seller, 50_000);
        $this->destination($seller);

        $request = $this->requestPayout($seller, 40_000);
        app(ApprovePayout::class)($request, $this->financeAdmin());

        $this->assertTrue(app(RecordPayoutSettlement::class)(
            $request, $this->financeAdmin(), 'wire', 'FT-99001',
        ));

        $this->assertFalse(
            app(RecordPayoutSettlement::class)($request->refresh(), $this->financeAdmin(), 'wire', 'FT-99001'),
            'A replayed settlement finds the work done.',
        );

        $this->assertSame(
            1,
            SellerLedgerEntry::query()->withoutGlobalScopes()
                ->where('type', LedgerEntryType::Payout->value)->count(),
        );
        $this->assertSame(10_000, $this->positionOf($seller)->withdrawableMinor());
    }

    #[Test]
    public function the_allocations_settle_rather_than_release(): void
    {
        ['seller' => $seller] = $this->makeSeller();

        $this->availableEarning($seller, 50_000);
        $this->destination($seller);

        $request = $this->requestPayout($seller, 40_000);
        app(ApprovePayout::class)($request, $this->financeAdmin());
        app(RecordPayoutSettlement::class)($request, $this->financeAdmin(), 'wire', 'FT-1');

        $this->assertSame(
            1,
            PayoutAllocation::query()->withoutGlobalScopes()
                ->where('payout_request_id', $request->id)->count(),
        );

        $allocation = PayoutAllocation::query()->withoutGlobalScopes()
            ->where('payout_request_id', $request->id)
            ->firstOrFail();

        $this->assertSame(PayoutAllocationStatus::Settled, $allocation->status);
        $this->assertNotNull($allocation->settled_at);
        $this->assertNull($allocation->released_at, 'Settled money was not also given back.');

        // §41: what was settled equals what was paid.
        $this->assertSame($request->amount_minor, $allocation->amount_minor);
    }

    // ---------------------------------------------------------------
    // 6. A refund after a payout
    // ---------------------------------------------------------------

    #[Test]
    public function a_refund_after_a_payout_leaves_the_payout_alone_and_the_balance_negative(): void
    {
        ['seller' => $seller] = $this->makeSeller();

        // §42's worked example: +90, paid out 90, then a 20 reversal.
        $earning = $this->availableEarning($seller, 9_000);
        $this->destination($seller);

        $request = $this->requestPayout($seller, 9_000);
        app(ApprovePayout::class)($request, $this->financeAdmin());
        app(RecordPayoutSettlement::class)($request, $this->financeAdmin(), 'wire', 'FT-2');

        $this->assertSame(0, $this->positionOf($seller)->netBalanceMinor());

        $this->reversal($seller, 2_000);

        $position = $this->positionOf($seller);

        $this->assertSame(-2_000, $position->availableMinor);
        $this->assertSame(-2_000, $position->netBalanceMinor());
        $this->assertTrue($position->isNegative());

        // The position is below zero; what may be withdrawn from it is
        // nothing, not a negative amount. The signed figure is kept
        // separately, because "how far short am I" is a real question.
        $this->assertSame(-2_000, $position->rawPayoutCapacityMinor());
        $this->assertSame(0, $position->withdrawableMinor());
        $this->assertFalse($position->hasWithdrawableFunds());

        // Neither historical record was rewritten.
        $this->assertSame(9_000, $earning->refresh()->amount_minor);
        $this->assertSame(PayoutStatus::Paid, $request->refresh()->status);
        $this->assertSame(9_000, $request->amount_minor);
        $this->assertSame(3, SellerLedgerEntry::query()->withoutGlobalScopes()->count());
    }

    #[Test]
    public function a_negative_seller_cannot_request_even_with_an_available_earning(): void
    {
        ['seller' => $seller] = $this->makeSeller();

        $this->availableEarning($seller, 9_000);
        $this->destination($seller);

        $request = $this->requestPayout($seller, 9_000);
        app(ApprovePayout::class)($request, $this->financeAdmin());
        app(RecordPayoutSettlement::class)($request, $this->financeAdmin(), 'wire', 'FT-3');

        $this->reversal($seller, 2_000);

        // §48: an individual available earning row exists (the original
        // +9,000), and the seller still may not withdraw.
        $this->assertTrue(
            SellerLedgerEntry::query()->withoutGlobalScopes()
                ->where('status', LedgerEntryStatus::Available->value)
                ->where('amount_minor', '>', 0)
                ->exists(),
        );

        $this->expectException(PayoutNotPermitted::class);
        $this->expectExceptionMessage('below zero after recent refunds');

        $this->requestPayout($seller, 5_000);
    }

    #[Test]
    public function a_later_earning_offsets_a_negative_balance(): void
    {
        ['seller' => $seller] = $this->makeSeller();

        $this->availableEarning($seller, 9_000);
        $this->destination($seller);

        $request = $this->requestPayout($seller, 9_000);
        app(ApprovePayout::class)($request, $this->financeAdmin());
        app(RecordPayoutSettlement::class)($request, $this->financeAdmin(), 'wire', 'FT-4');
        $this->reversal($seller, 2_000);

        // §43: -20 then +50 nets to +30, and it is withdrawable only once
        // the new earning has cleared on its own schedule.
        $clearing = $this->clearingEarning($seller, 5_000);

        $mid = $this->positionOf($seller);
        $this->assertSame(3_000, $mid->netBalanceMinor(), 'The debt is offset in the net position...');
        $this->assertSame(-2_000, $mid->availableMinor, '...but the new money has not cleared yet.');
        $this->assertSame(-2_000, $mid->rawPayoutCapacityMinor(), 'Still 2,000 short.');
        $this->assertSame(0, $mid->withdrawableMinor(), 'And nothing may be withdrawn.');

        $clearing->forceFill(['status' => LedgerEntryStatus::Available->value])->save();

        $after = $this->positionOf($seller);
        $this->assertSame(3_000, $after->availableMinor);
        $this->assertSame(3_000, $after->withdrawableMinor());
    }

    // ---------------------------------------------------------------
    // Rejection, cancellation and failure release policy
    // ---------------------------------------------------------------

    #[Test]
    public function rejecting_releases_the_hold_and_writes_no_debit(): void
    {
        ['seller' => $seller] = $this->makeSeller();

        $this->availableEarning($seller, 50_000);
        $this->destination($seller);

        $request = $this->requestPayout($seller, 40_000);

        $this->assertTrue(app(RejectPayout::class)($request, $this->financeAdmin(), 'Bank details do not match.'));

        $position = $this->positionOf($seller);
        $this->assertSame(0, $position->reservedMinor);
        $this->assertSame(50_000, $position->withdrawableMinor());
        $this->assertSame(
            0,
            SellerLedgerEntry::query()->withoutGlobalScopes()
                ->where('type', LedgerEntryType::Payout->value)->count(),
        );

        $fresh = $request->refresh();
        $this->assertSame(PayoutStatus::Rejected, $fresh->status);
        $this->assertSame('Bank details do not match.', $fresh->decision_reason);
        $this->assertSame(2, $fresh->history()->count(), 'Requested, then rejected — both kept.');
    }

    #[Test]
    public function rejecting_twice_does_not_release_twice(): void
    {
        ['seller' => $seller] = $this->makeSeller();

        $this->availableEarning($seller, 50_000);
        $this->destination($seller);

        $request = $this->requestPayout($seller, 40_000);

        app(RejectPayout::class)($request, $this->financeAdmin(), 'No.');

        $this->assertFalse(
            app(RejectPayout::class)($request->refresh(), $this->financeAdmin(), 'No.'),
            'The second rejection finds it already rejected.',
        );

        $this->assertSame(50_000, $this->positionOf($seller)->withdrawableMinor());
        $this->assertSame(
            1,
            PayoutAllocation::query()->withoutGlobalScopes()
                ->where('status', PayoutAllocationStatus::Released->value)->count(),
        );
    }

    #[Test]
    public function rejection_requires_a_reason(): void
    {
        ['seller' => $seller] = $this->makeSeller();

        $this->availableEarning($seller, 50_000);
        $this->destination($seller);

        $request = $this->requestPayout($seller, 40_000);

        $this->expectException(PayoutNotPermitted::class);
        $this->expectExceptionMessage('reason is required');

        app(RejectPayout::class)($request, $this->financeAdmin(), '   ');
    }

    #[Test]
    public function a_seller_may_cancel_before_review_and_not_after(): void
    {
        ['seller' => $seller] = $this->makeSeller();

        $this->availableEarning($seller, 50_000);
        $this->destination($seller);

        $first = $this->requestPayout($seller, 40_000);
        $this->assertTrue(app(CancelPayoutRequest::class)($first, PayoutActor::seller(null)));
        $this->assertSame(PayoutStatus::Cancelled, $first->refresh()->status);
        $this->assertSame(50_000, $this->positionOf($seller)->withdrawableMinor());

        $second = $this->requestPayout($seller, 40_000);
        app(StartPayoutReview::class)($second, $this->financeAdmin());

        $this->expectException(PayoutNotPermitted::class);
        $this->expectExceptionMessage('can no longer be cancelled by the store');

        app(CancelPayoutRequest::class)($second->refresh(), PayoutActor::seller(null));
    }

    #[Test]
    public function a_failed_settlement_keeps_the_money_held(): void
    {
        ['seller' => $seller] = $this->makeSeller();

        $this->availableEarning($seller, 50_000);
        $this->destination($seller);

        $request = $this->requestPayout($seller, 40_000);
        app(ApprovePayout::class)($request, $this->financeAdmin());

        app(FailPayoutSettlement::class)(
            $request, $this->financeAdmin(), 'The wire was returned by the beneficiary bank.', 'returned',
        );

        // §30, the chosen policy: failure is visible and the hold stands.
        $position = $this->positionOf($seller);
        $this->assertSame(PayoutStatus::Failed, $request->refresh()->status);
        $this->assertSame(40_000, $position->reservedMinor, 'Still held: finance has not finished with it.');
        $this->assertSame(10_000, $position->withdrawableMinor());

        // The attempt is a permanent record with its reason.
        $attempt = PayoutSettlementAttempt::query()->where('payout_request_id', $request->id)->firstOrFail();
        $this->assertSame('returned', $attempt->failure_code);
        $this->assertStringContainsString('returned by the beneficiary', (string) $attempt->failure_message);

        // Ending it deliberately is what gives the money back.
        app(CancelPayoutRequest::class)($request->refresh(), $this->financeAdmin(), 'Abandoned after two attempts.');
        $this->assertSame(50_000, $this->positionOf($seller)->withdrawableMinor());
    }

    #[Test]
    public function a_rejected_payout_can_never_be_settled(): void
    {
        ['seller' => $seller] = $this->makeSeller();

        $this->availableEarning($seller, 50_000);
        $this->destination($seller);

        $request = $this->requestPayout($seller, 40_000);
        app(RejectPayout::class)($request, $this->financeAdmin(), 'No.');

        $this->expectException(PayoutNotPermitted::class);
        $this->expectExceptionMessage('cannot be settled');

        app(RecordPayoutSettlement::class)($request->refresh(), $this->financeAdmin(), 'wire', 'FT-5');
    }

    #[Test]
    public function settlement_demands_a_reference_it_can_be_reconciled_by(): void
    {
        ['seller' => $seller] = $this->makeSeller();

        $this->availableEarning($seller, 50_000);
        $this->destination($seller);

        $request = $this->requestPayout($seller, 40_000);
        app(ApprovePayout::class)($request, $this->financeAdmin());

        $this->expectException(PayoutNotPermitted::class);
        $this->expectExceptionMessage('Record the reference');

        app(RecordPayoutSettlement::class)($request, $this->financeAdmin(), 'wire', '  ');
    }

    // ---------------------------------------------------------------
    // Currency
    // ---------------------------------------------------------------

    #[Test]
    public function two_currencies_are_two_balances(): void
    {
        ['seller' => $seller] = $this->makeSeller();

        $this->availableEarning($seller, 50_000, 'USD');
        $this->availableEarning($seller, 30_000, 'EUR');

        $this->assertSame(50_000, $this->positionOf($seller, 'USD')->withdrawableMinor());
        $this->assertSame(30_000, $this->positionOf($seller, 'EUR')->withdrawableMinor());

        $this->destination($seller, 'EUR');

        // Phase 1 supports USD only, and says so rather than paying it.
        $this->expectException(PayoutNotPermitted::class);
        $this->expectExceptionMessage('not available in this currency');

        $this->requestPayout($seller, 20_000, 'EUR');
    }

    #[Test]
    public function zero_and_negative_amounts_are_refused(): void
    {
        ['seller' => $seller] = $this->makeSeller();

        $this->availableEarning($seller, 50_000);
        $this->destination($seller);

        foreach ([0, -5_000] as $amount) {
            try {
                $this->requestPayout($seller, $amount);
                $this->fail("A payout of {$amount} must be refused.");
            } catch (PayoutNotPermitted $refused) {
                $this->assertSame('amount_not_positive', $refused->reason);
            }
        }
    }

    #[Test]
    public function a_payout_request_cannot_have_its_amount_edited(): void
    {
        ['seller' => $seller] = $this->makeSeller();

        $this->availableEarning($seller, 50_000);
        $this->destination($seller);

        $request = $this->requestPayout($seller, 40_000);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('immutable');

        $request->update(['amount_minor' => 50_000]);
    }
}
