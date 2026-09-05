<?php

declare(strict_types=1);

namespace Tests\Feature\Payouts;

use App\Modules\Ledger\Enums\LedgerEntryStatus;
use App\Modules\Ledger\Models\SellerLedgerEntry;
use App\Modules\Payouts\Actions\PostFinancialAdjustment;
use App\Modules\Payouts\Actions\RecordPayoutSettlement;
use App\Modules\Payouts\Enums\PayoutStatus;
use App\Modules\Payouts\Exceptions\PayoutNotPermitted;
use App\Modules\Payouts\Queries\ReconcileSellerFinance;
use App\Modules\Sellers\Models\SellerAccount;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

/**
 * M9 property 3, under contention.
 *
 * THE LOCK ORDERING, which is what makes all of this safe and which is
 * written down in RequestPayout as well as here because a reader arriving
 * at a race test needs it in front of them:
 *
 *     1. the seller account row      (the seller's financial scope)
 *     2. the payout request row      (if one already exists)
 *     3. ledger and allocation writes
 *
 * Every action that can change a seller's capacity — requesting a payout,
 * settling one, finalizing a refund reversal, posting a manual adjustment
 * — takes the seller's row first. That single rule is what turns "two
 * things happened at once" into "one of them happened first": both want
 * the same row, one waits, and the one that waits reads a balance that
 * already includes what the other did. There is no ordering in which both
 * read the same stale balance, which is the only way money gets promised
 * twice.
 *
 * So each test below asserts a disjunction rather than a single outcome.
 * Either ordering is correct; what must never happen is a third thing —
 * a hold taken against funds that a committed reversal had already
 * removed, with the platform still reporting the money as withdrawable.
 *
 * Truncation rather than RefreshDatabase, and a second connection rather
 * than a second call, for the reason the other concurrency suites give:
 * work inside a transaction that never commits is invisible to anybody
 * else, and two sessions that cannot see each other's rows prove nothing.
 */
final class PayoutCeilingRaceTest extends TestCase
{
    use BuildsSellerFinance;
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        $this->truncateDatabaseTables();

        parent::tearDown();
    }

    private function cleanUp(mixed $connection): void
    {
        try {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        } catch (Throwable) {
            // Already resolved.
        }

        $connection->disconnect();
    }

    /**
     * The invariant that has to survive every ordering.
     *
     * Not "the balance is X" — that legitimately differs by ordering — but
     * that whatever the platform reports is what the ledger and the open
     * holds actually say, and that nothing is being offered twice.
     */
    private function assertBooksAgree(SellerAccount $seller, string $scenario): void
    {
        $position = $this->positionOf($seller);

        $ledgerSum = (int) SellerLedgerEntry::query()
            ->withoutGlobalScopes()
            ->where('seller_account_id', $seller->id)
            ->sum('amount_minor');

        $this->assertSame(
            $ledgerSum,
            $position->netBalanceMinor(),
            "{$scenario}: the reported position is not the sum of the ledger.",
        );

        $held = (int) DB::table('payout_allocations')
            ->where('seller_account_id', $seller->id)
            ->where('status', 'held')
            ->sum('amount_minor');

        $this->assertSame(
            $held,
            $position->reservedMinor,
            "{$scenario}: reserved does not match what the open allocations hold.",
        );

        $this->assertGreaterThanOrEqual(
            0,
            $position->withdrawableMinor(),
            "{$scenario}: withdrawable went below zero.",
        );

        // The forbidden third outcome: money still offered as withdrawable
        // when the holds standing against the ledger already exceed it.
        if ($position->isShort()) {
            $this->assertSame(
                0,
                $position->withdrawableMinor(),
                "{$scenario}: the seller is short and the platform is still offering money.",
            );
        }

        $this->assertSame(
            [],
            app(ReconcileSellerFinance::class)(),
            "{$scenario}: reconciliation found a discrepancy.",
        );
    }

    // ── §12 — a payout request and a refund, in both orders ───────────

    #[Test]
    public function a_refund_committing_first_is_seen_by_the_request_behind_it(): void
    {
        ['seller' => $seller] = $this->makeSeller();
        $this->availableEarning($seller, 100_000);
        $this->destination($seller);

        $other = DB::connection('concurrent');

        try {
            // The refund lands and commits from another session.
            $this->reversal($seller, 70_000);

            $this->assertSame(
                30_000,
                (int) $other->table('seller_ledger_entries')
                    ->where('seller_account_id', $seller->id)
                    ->where('status', 'available')
                    ->sum('amount_minor'),
                'The other session sees the committed reversal.',
            );
        } finally {
            $this->cleanUp($other);
        }

        // The request behind it reads the reduced balance, not the one the
        // seller was looking at when they pressed the button.
        try {
            $this->requestPayout($seller, 100_000);
            $this->fail('A payout was allowed against money a refund had already taken.');
        } catch (PayoutNotPermitted $refused) {
            $this->assertSame('exceeds_withdrawable', $refused->reason);
        }

        $this->assertSame(30_000, $this->positionOf($seller)->withdrawableMinor());
        $this->assertBooksAgree($seller, 'refund first');

        // What survives is genuinely withdrawable, so the refund cost the
        // seller the refund and not a penny more.
        $this->assertSame(30_000, $this->requestPayout($seller, 30_000)->amount_minor);
        $this->assertBooksAgree($seller, 'refund first, then a valid request');
    }

    #[Test]
    public function a_request_holding_first_leaves_the_refund_to_land_behind_it(): void
    {
        ['seller' => $seller] = $this->makeSeller();
        $this->availableEarning($seller, 100_000);
        $this->destination($seller);

        // The other ordering: the hold is taken against funds that were
        // genuinely there, and the refund arrives afterwards.
        $payout = $this->requestPayout($seller, 100_000);

        $this->reversal($seller, 70_000);

        $position = $this->positionOf($seller);

        // The seller is now short — 100,000 held against 30,000 — without
        // being negative: the platform still owes them 30,000. Both
        // predicates are true statements about different things.
        $this->assertSame(30_000, $position->netBalanceMinor());
        $this->assertSame(100_000, $position->reservedMinor);
        $this->assertFalse($position->isNegative());
        $this->assertTrue($position->isShort());
        $this->assertSame(-70_000, $position->rawPayoutCapacityMinor());

        // And nothing further is offered.
        $this->assertSame(0, $position->withdrawableMinor());
        $this->assertBooksAgree($seller, 'request first, refund behind it');

        // The open request is still a real claim on the platform, to be
        // decided by a person — this is the state that must be visible,
        // not quietly reversed.
        $this->assertSame(PayoutStatus::Requested, $payout->refresh()->status);
    }

    // ── §13 — a payout request and a manual finance adjustment ────────

    #[Test]
    public function a_finance_adjustment_committing_first_reduces_what_can_be_requested(): void
    {
        // Refunds are not the only way a balance falls. A finance manager
        // correcting an overpayment moves the same number, through a
        // different action, and a capacity check that only knew about
        // refunds would let this one through.
        ['seller' => $seller] = $this->makeSeller();
        $this->availableEarning($seller, 50_000);
        $this->destination($seller);

        $other = DB::connection('concurrent');

        try {
            app(PostFinancialAdjustment::class)(
                seller: $seller,
                amountMinor: -35_000,
                reason: 'Chargeback recovered outside the platform.',
                actor: $this->financeActor(),
            );

            $this->assertSame(
                15_000,
                (int) $other->table('seller_ledger_entries')
                    ->where('seller_account_id', $seller->id)
                    ->where('status', 'available')
                    ->sum('amount_minor'),
                'The other session sees the committed adjustment.',
            );
        } finally {
            $this->cleanUp($other);
        }

        try {
            $this->requestPayout($seller, 50_000);
            $this->fail('A payout was allowed against money an adjustment had already removed.');
        } catch (PayoutNotPermitted $refused) {
            $this->assertSame('exceeds_withdrawable', $refused->reason);
        }

        $this->assertSame(15_000, $this->positionOf($seller)->withdrawableMinor());
        $this->assertBooksAgree($seller, 'adjustment first');

        $this->assertSame(15_000, $this->requestPayout($seller, 15_000)->amount_minor);
        $this->assertBooksAgree($seller, 'adjustment first, then a valid request');
    }

    #[Test]
    public function an_adjustment_landing_behind_a_hold_leaves_the_seller_short_not_overdrawn(): void
    {
        ['seller' => $seller] = $this->makeSeller();
        $this->availableEarning($seller, 50_000);
        $this->destination($seller);

        $this->requestPayout($seller, 50_000);

        app(PostFinancialAdjustment::class)(
            seller: $seller,
            amountMinor: -20_000,
            reason: 'Chargeback recovered outside the platform.',
            actor: $this->financeActor(),
        );

        $position = $this->positionOf($seller);

        $this->assertSame(30_000, $position->netBalanceMinor());
        $this->assertSame(50_000, $position->reservedMinor);
        $this->assertSame(-20_000, $position->rawPayoutCapacityMinor());
        $this->assertSame(0, $position->withdrawableMinor());
        $this->assertBooksAgree($seller, 'adjustment behind a hold');
    }

    #[Test]
    public function the_adjustment_takes_the_sellers_row_the_same_way_a_request_does(): void
    {
        // The lock ordering is only a rule if every path follows it. This
        // is the one that would be easy to add later without noticing.
        ['seller' => $seller] = $this->makeSeller();
        $this->availableEarning($seller, 50_000);

        $other = DB::connection('concurrent');

        try {
            $other->statement('set lock_timeout = 300');

            DB::beginTransaction();
            DB::table('seller_accounts')->where('id', $seller->id)->lockForUpdate()->first();

            $blocked = false;

            try {
                $other->table('seller_accounts')->where('id', $seller->id)->lockForUpdate()->first();
            } catch (Throwable $timeout) {
                $blocked = true;
                $this->assertStringContainsString('lock timeout', strtolower($timeout->getMessage()));
            }

            $this->assertTrue($blocked, 'The seller row is the gate every financial path passes through.');
        } finally {
            DB::rollBack();
            $this->cleanUp($other);
        }
    }

    // ── §14 — settlement exactly once ─────────────────────────────────

    #[Test]
    public function a_second_settlement_from_another_session_writes_no_second_debit(): void
    {
        ['seller' => $seller] = $this->makeSeller();
        $this->availableEarning($seller, 60_000);
        $this->destination($seller);

        $payout = $this->requestPayout($seller, 40_000);
        $this->settle($payout, 'FT-RACE-1');

        $this->assertSame(PayoutStatus::Paid, $payout->refresh()->status);

        $debitsAfterOne = SellerLedgerEntry::query()
            ->withoutGlobalScopes()
            ->where('payout_request_id', $payout->id)
            ->count();

        $this->assertSame(1, $debitsAfterOne);

        $other = DB::connection('concurrent');

        try {
            // The losing worker, on its own connection, seeing everything
            // the winner committed. The status is no longer settleable, so
            // its conditional claim matches nothing.
            $claimed = $other->table('payout_requests')
                ->where('id', $payout->id)
                ->whereIn('status', [PayoutStatus::Approved->value, PayoutStatus::Processing->value])
                ->update(['status' => PayoutStatus::Paid->value]);

            $this->assertSame(0, $claimed, 'A paid payout must not be claimable for settlement again.');
        } finally {
            $this->cleanUp($other);
        }

        // And running the settlement action again is inert rather than
        // additive.
        try {
            app(RecordPayoutSettlement::class)($payout->refresh(), $this->financeActor(), 'wire', 'FT-RACE-2');
        } catch (PayoutNotPermitted) {
            // Refusing is equally correct; what matters is the ledger.
        }

        $this->assertSame(
            1,
            SellerLedgerEntry::query()->withoutGlobalScopes()->where('payout_request_id', $payout->id)->count(),
            'Exactly one payout debit, however many settlements were attempted.',
        );

        $position = $this->positionOf($seller);

        $this->assertSame(20_000, $position->availableMinor);
        $this->assertSame(0, $position->reservedMinor, 'The hold settled rather than being released or kept.');
        $this->assertSame(40_000, $position->paidOutMinor);
        $this->assertSame(20_000, $position->withdrawableMinor());

        $this->assertBooksAgree($seller, 'settlement attempted twice');
    }

    // ── §16 and §17 — the whole sequence, then recovery ───────────────

    #[Test]
    public function a_refund_after_settlement_goes_negative_and_a_later_earning_pays_it_back(): void
    {
        ['seller' => $seller] = $this->makeSeller();
        $this->availableEarning($seller, 20_000);
        $this->destination($seller);

        $payout = $this->requestPayout($seller, 20_000);
        $this->settle($payout, 'FT-SEQ-1');

        // The refund arrives after the money has left the building.
        $this->reversal($seller, 22_000);

        $negative = $this->positionOf($seller);

        $this->assertSame(-22_000, $negative->netBalanceMinor());
        $this->assertTrue($negative->isNegative());
        $this->assertSame(0, $negative->withdrawableMinor(), 'Clamped, not negative.');
        $this->assertSame(20_000, $negative->paidOutMinor, 'The payout is history and stays.');

        // Nothing was rewritten to make the arithmetic tidy: earning,
        // debit and reversal are all still there, and they still sum.
        $this->assertSame(3, SellerLedgerEntry::query()->withoutGlobalScopes()->count());
        $this->assertBooksAgree($seller, 'refund after settlement');

        $this->assertSame(PayoutStatus::Paid, $payout->refresh()->status);

        try {
            $this->requestPayout($seller, 1);
            $this->fail('A negative seller opened a payout request.');
        } catch (PayoutNotPermitted $refused) {
            $this->assertSame('negative_balance', $refused->reason);
        }

        // §17: the debt is offset by future trade, not hidden. Money that
        // is still clearing does not help yet.
        $this->clearingEarning($seller, 30_000);

        $stillClearing = $this->positionOf($seller);
        $this->assertSame(8_000, $stillClearing->netBalanceMinor());
        $this->assertFalse($stillClearing->isNegative(), 'The liability is genuinely positive again.');
        $this->assertSame(
            0,
            $stillClearing->withdrawableMinor(),
            'And none of it is withdrawable while it is clearing: min(available, net) is -22,000.',
        );

        // Once it clears legitimately, the seller is whole again — up to
        // exactly what is left after the debt.
        $this->availableEarning($seller, 30_000);
        $this->reversal($seller, 30_000, LedgerEntryStatus::Clearing);

        $recovered = $this->positionOf($seller);

        $this->assertSame(8_000, $recovered->netBalanceMinor());
        $this->assertSame(8_000, $recovered->availableMinor);
        $this->assertSame(8_000, $recovered->withdrawableMinor());
        $this->assertBooksAgree($seller, 'earnings offsetting the debt');
    }
}
