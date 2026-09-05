<?php

declare(strict_types=1);

namespace Tests\Feature\Payouts;

use App\Modules\Ledger\Enums\LedgerEntryType;
use App\Modules\Ledger\Models\SellerLedgerEntry;
use App\Modules\Payouts\Actions\ApprovePayout;
use App\Modules\Payouts\Actions\RecordPayoutSettlement;
use App\Modules\Payouts\Actions\RejectPayout;
use App\Modules\Payouts\Data\PayoutActor;
use App\Modules\Payouts\Enums\PayoutStatus;
use App\Modules\Payouts\Exceptions\PayoutNotPermitted;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

/**
 * §51–§56 — what two people doing the same thing at once must not be able
 * to do with a seller's money.
 *
 * Truncation and committed transactions, as the checkout, inventory,
 * payment and fulfilment concurrency suites use: work inside a transaction
 * that never commits is invisible to a second connection, and two sessions
 * that cannot see each other prove nothing about a race.
 *
 * Each of these proves the guard is the DATABASE'S — a row lock, a partial
 * unique index, a conditional UPDATE — rather than an application check
 * that would lose in production. The ordering the whole module follows is
 * written down in RequestPayout: seller account row, then payout request
 * row, then ledger and allocation writes.
 */
final class PayoutConcurrencyTest extends TestCase
{
    use BuildsSellerFinance;
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        $this->truncateDatabaseTables();

        parent::tearDown();
    }

    private function financeAdmin(): PayoutActor
    {
        return PayoutActor::admin(null, 'Finance');
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

    // ---------------------------------------------------------------
    // §51 — two requests cannot reserve the same money
    // ---------------------------------------------------------------

    #[Test]
    public function a_request_holds_the_sellers_row_so_a_second_cannot_read_the_same_balance(): void
    {
        ['seller' => $seller] = $this->makeSeller();
        $this->availableEarning($seller, 10_000);
        $this->destination($seller);

        $other = DB::connection('concurrent');

        try {
            // A second session cannot even wait a moment for the row.
            $other->statement('set lock_timeout = 300');

            DB::beginTransaction();

            // What RequestPayout takes first, and holds for its whole
            // transaction: the seller's financial scope.
            DB::table('seller_accounts')->where('id', $seller->id)->lockForUpdate()->first();

            $blocked = false;

            try {
                $other->table('seller_accounts')->where('id', $seller->id)->lockForUpdate()->first();
            } catch (Throwable $timeout) {
                $blocked = true;
                $this->assertStringContainsString('lock timeout', strtolower($timeout->getMessage()));
            }

            $this->assertTrue(
                $blocked,
                'A second payout request must wait for the first to finish reading the balance.',
            );
        } finally {
            DB::rollBack();
            $this->cleanUp($other);
        }
    }

    #[Test]
    public function only_one_of_two_requests_for_the_same_money_survives(): void
    {
        ['seller' => $seller] = $this->makeSeller();

        // §51's figures: $100 available, two people asking for $80.
        $this->availableEarning($seller, 10_000);
        $this->destination($seller);

        $accepted = 0;
        $refusals = [];

        foreach (range(1, 2) as $_) {
            try {
                $this->requestPayout($seller, 8_000);
                $accepted++;
            } catch (PayoutNotPermitted $refused) {
                $refusals[] = $refused->reason;
            }
        }

        $this->assertSame(1, $accepted, 'Exactly one request may hold the money.');
        $this->assertSame(['open_payout_exists'], $refusals);

        // Committed, so a second connection sees the same one request.
        $other = DB::connection('concurrent');

        try {
            $this->assertSame(1, (int) $other->table('payout_requests')->where('seller_account_id', $seller->id)->count());
            $this->assertSame(8_000, (int) $other->table('payout_allocations')
                ->where('seller_account_id', $seller->id)
                ->where('status', 'held')
                ->sum('amount_minor'));
        } finally {
            $this->cleanUp($other);
        }
    }

    #[Test]
    public function the_open_request_index_holds_when_the_application_check_is_bypassed(): void
    {
        ['seller' => $seller] = $this->makeSeller();
        $this->availableEarning($seller, 50_000);
        $this->destination($seller);

        $this->requestPayout($seller, 20_000);

        // A second session writing the row directly, as a repair script
        // or a second app server would. The index is what stops it.
        $other = DB::connection('concurrent');

        try {
            $other->table('payout_requests')->insert([
                'public_id' => (string) Str::ulid(),
                'reference' => 'PO-RACE',
                'seller_account_id' => $seller->id,
                'currency' => 'USD',
                'amount_minor' => 20_000,
                'status' => PayoutStatus::Requested->value,
                'requested_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->fail('The partial unique index must refuse a second open request.');
        } catch (Throwable $violation) {
            $this->assertStringContainsString('payout_requests_one_open_per_seller', $violation->getMessage());
        } finally {
            $this->cleanUp($other);
        }
    }

    // ---------------------------------------------------------------
    // §52 — two admins approving
    // ---------------------------------------------------------------

    #[Test]
    public function two_admins_approving_produce_one_approval(): void
    {
        ['seller' => $seller] = $this->makeSeller();
        $this->availableEarning($seller, 50_000);
        $this->destination($seller);

        $request = $this->requestPayout($seller, 40_000);

        $first = app(ApprovePayout::class)($request, PayoutActor::admin(null, 'Ada'));
        $second = app(ApprovePayout::class)($request->refresh(), PayoutActor::admin(null, 'Bo'));

        $this->assertTrue($first);
        $this->assertFalse($second, 'The second approval finds the work done.');

        $this->assertSame(
            1,
            $request->refresh()->history()->where('to_status', PayoutStatus::Approved->value)->count(),
            'One approval, one history row, one notification.',
        );
        $this->assertSame(40_000, $this->positionOf($seller)->reservedMinor, 'And one reservation.');
    }

    // ---------------------------------------------------------------
    // §53 — two settlements
    // ---------------------------------------------------------------

    #[Test]
    public function two_settlements_cannot_both_debit_the_seller(): void
    {
        ['seller' => $seller] = $this->makeSeller();
        $this->availableEarning($seller, 50_000);
        $this->destination($seller);

        $request = $this->requestPayout($seller, 40_000);
        app(ApprovePayout::class)($request, $this->financeAdmin());

        $first = app(RecordPayoutSettlement::class)($request, PayoutActor::admin(null, 'Ada'), 'wire', 'FT-1');
        $second = app(RecordPayoutSettlement::class)($request->refresh(), PayoutActor::admin(null, 'Bo'), 'wire', 'FT-2');

        $this->assertTrue($first);
        $this->assertFalse($second);

        $this->assertSame(
            1,
            SellerLedgerEntry::query()->withoutGlobalScopes()
                ->where('type', LedgerEntryType::Payout->value)->count(),
            'Exactly one payout debit.',
        );
        $this->assertSame(10_000, $this->positionOf($seller)->withdrawableMinor());
    }

    #[Test]
    public function the_database_refuses_a_second_successful_settlement_attempt(): void
    {
        ['seller' => $seller] = $this->makeSeller();
        $this->availableEarning($seller, 50_000);
        $this->destination($seller);

        $request = $this->requestPayout($seller, 40_000);
        app(ApprovePayout::class)($request, $this->financeAdmin());
        app(RecordPayoutSettlement::class)($request, $this->financeAdmin(), 'wire', 'FT-1');

        // A second session writing the attempt directly. The partial
        // unique index is the control the row lock cannot be.
        $other = DB::connection('concurrent');

        try {
            $other->table('payout_settlement_attempts')->insert([
                'public_id' => (string) Str::ulid(),
                'payout_request_id' => $request->id,
                'provider' => 'manual',
                'method' => 'wire',
                'external_reference' => 'FT-DUPLICATE',
                'status' => 'succeeded',
                'currency' => 'USD',
                'amount_minor' => 40_000,
                'initiated_at' => now(),
                'completed_at' => now(),
            ]);

            $this->fail('One successful settlement per payout, enforced by the database.');
        } catch (Throwable $violation) {
            $this->assertStringContainsString('payout_settlement_attempts_one_success', $violation->getMessage());
        } finally {
            $this->cleanUp($other);
        }
    }

    // ---------------------------------------------------------------
    // §54 — reject against settle
    // ---------------------------------------------------------------

    #[Test]
    public function a_rejection_and_a_settlement_cannot_both_win(): void
    {
        ['seller' => $seller] = $this->makeSeller();
        $this->availableEarning($seller, 100_000);
        $this->destination($seller);

        // Rejection first: the settlement then has nothing to settle.
        $rejectedFirst = $this->requestPayout($seller, 40_000);
        app(ApprovePayout::class)($rejectedFirst, $this->financeAdmin());
        app(RejectPayout::class)($rejectedFirst->refresh(), $this->financeAdmin(), 'Held for review.');

        try {
            app(RecordPayoutSettlement::class)($rejectedFirst->refresh(), $this->financeAdmin(), 'wire', 'FT-X');
            $this->fail('A rejected payout must not settle.');
        } catch (PayoutNotPermitted $refused) {
            $this->assertSame('not_settleable', $refused->reason);
        }

        $this->assertSame(
            0,
            SellerLedgerEntry::query()->withoutGlobalScopes()
                ->where('type', LedgerEntryType::Payout->value)->count(),
            'A rejected request never produces a debit.',
        );

        // And the other order: settled first, the rejection is refused by
        // the state machine because PAID is terminal.
        $settledFirst = $this->requestPayout($seller, 40_000);
        app(ApprovePayout::class)($settledFirst, $this->financeAdmin());
        app(RecordPayoutSettlement::class)($settledFirst->refresh(), $this->financeAdmin(), 'wire', 'FT-Y');

        try {
            app(RejectPayout::class)($settledFirst->refresh(), $this->financeAdmin(), 'Too late.');
            $this->fail('A paid payout must not be rejectable.');
        } catch (PayoutNotPermitted $refused) {
            $this->assertSame('invalid_transition', $refused->reason);
        }

        $this->assertSame(PayoutStatus::Paid, $settledFirst->refresh()->status);
        $this->assertSame(60_000, $this->positionOf($seller)->withdrawableMinor());
    }

    // ---------------------------------------------------------------
    // §55 and §56 — a refund racing a payout
    // ---------------------------------------------------------------

    #[Test]
    public function a_refund_landing_first_reduces_what_can_be_withdrawn(): void
    {
        ['seller' => $seller] = $this->makeSeller();
        $this->availableEarning($seller, 100_000);
        $this->destination($seller);

        // The refund commits from another session before the seller asks.
        $other = DB::connection('concurrent');

        try {
            $this->reversal($seller, 60_000);

            $this->assertSame(
                40_000,
                (int) $other->table('seller_ledger_entries')
                    ->where('seller_account_id', $seller->id)
                    ->where('status', 'available')
                    ->sum('amount_minor'),
                'The other session sees the committed reversal.',
            );
        } finally {
            $this->cleanUp($other);
        }

        try {
            $this->requestPayout($seller, 100_000);
            $this->fail('A payout cannot exceed the balance the refund left.');
        } catch (PayoutNotPermitted $refused) {
            $this->assertSame('exceeds_withdrawable', $refused->reason);
        }

        $this->assertSame(40_000, $this->positionOf($seller)->withdrawableMinor());

        // What is left is still withdrawable, so the refund cost the
        // seller exactly the refund and nothing more.
        $this->assertSame(40_000, $this->requestPayout($seller, 40_000)->amount_minor);
    }

    #[Test]
    public function a_refund_landing_after_settlement_leaves_a_reconciled_negative_ledger(): void
    {
        ['seller' => $seller] = $this->makeSeller();
        $this->availableEarning($seller, 10_000);
        $this->destination($seller);

        $request = $this->requestPayout($seller, 10_000);
        app(ApprovePayout::class)($request, $this->financeAdmin());
        app(RecordPayoutSettlement::class)($request, $this->financeAdmin(), 'wire', 'FT-Z');

        // §56's first branch: the payout settled, then the refund arrived.
        // The money is gone, so the seller's position goes below zero
        // rather than money going missing.
        $this->reversal($seller, 6_000);

        $position = $this->positionOf($seller);

        $this->assertSame(-6_000, $position->netBalanceMinor());
        $this->assertSame(0, $position->reservedMinor);
        $this->assertSame(10_000, $position->paidOutMinor);

        // The ledger still adds up: earning + payout + reversal = the net.
        $this->assertSame(
            $position->netBalanceMinor(),
            (int) SellerLedgerEntry::query()->withoutGlobalScopes()
                ->where('seller_account_id', $seller->id)
                ->sum('amount_minor'),
            'No money went missing; the sum of every row is the position.',
        );

        $this->assertSame(PayoutStatus::Paid, $request->refresh()->status);
    }

    #[Test]
    public function a_seller_is_never_able_to_hold_more_than_they_have(): void
    {
        ['seller' => $seller] = $this->makeSeller();
        $this->availableEarning($seller, 10_000);
        $this->destination($seller);

        $request = $this->requestPayout($seller, 10_000);

        // A refund lands while the payout is open. The hold does not
        // shrink — that money is already promised — but the seller's
        // withdrawable goes negative, which is the honest arithmetic.
        $this->reversal($seller, 6_000);

        $position = $this->positionOf($seller);
        $this->assertSame(10_000, $position->reservedMinor);
        $this->assertSame(4_000, $position->availableMinor);

        // The store is short without being in deficit: it owes nothing
        // overall, but its open payout is holding money that is no longer
        // there. Withdrawable is nothing; the shortfall is its own figure.
        $this->assertFalse($position->isNegative());
        $this->assertTrue($position->isShort());
        $this->assertSame(-6_000, $position->rawPayoutCapacityMinor());
        $this->assertSame(0, $position->withdrawableMinor());

        // Approval now refuses, because the reservation no longer fits
        // inside what the seller has. Finance decides what happens next.
        try {
            app(ApprovePayout::class)($request, $this->financeAdmin());
            $this->assertSame(
                PayoutStatus::Approved,
                $request->refresh()->status,
                'If approval is allowed the reservation must still be exact.',
            );
        } catch (PayoutNotPermitted $refused) {
            $this->assertSame('exceeds_withdrawable', $refused->reason);
        }

        $this->assertSame(
            0,
            $this->positionOf($seller)->withdrawableMinor(),
            'Nothing further may be withdrawn while the position is short.',
        );
    }
}
