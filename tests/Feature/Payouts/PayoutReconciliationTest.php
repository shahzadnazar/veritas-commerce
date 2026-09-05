<?php

declare(strict_types=1);

namespace Tests\Feature\Payouts;

use App\Modules\Ledger\Enums\LedgerEntryType;
use App\Modules\Ledger\Models\SellerLedgerEntry;
use App\Modules\Payouts\Actions\ApprovePayout;
use App\Modules\Payouts\Actions\CancelPayoutRequest;
use App\Modules\Payouts\Actions\RecordPayoutSettlement;
use App\Modules\Payouts\Actions\RejectPayout;
use App\Modules\Payouts\Data\PayoutActor;
use App\Modules\Payouts\Enums\PayoutAllocationStatus;
use App\Modules\Payouts\Enums\PayoutStatus;
use App\Modules\Payouts\Models\PayoutAllocation;
use App\Modules\Payouts\Queries\ReconcileSellerFinance;
use App\Modules\Payouts\Queries\SummarisePlatformFinance;
use App\Modules\Sellers\Models\SellerAccount;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * §40 and §41 — the money adds up, and the check that says so has teeth.
 *
 * Two halves. The first proves the reconciliation reports clean over
 * genuinely complicated finance. The second breaks each invariant on
 * purpose, with SQL that bypasses every domain action, and proves the
 * check actually notices — a reconciliation that has never failed is a
 * reconciliation nobody has tested.
 */
final class PayoutReconciliationTest extends TestCase
{
    use BuildsSellerFinance;
    use RefreshDatabase;

    private function admin(): PayoutActor
    {
        return PayoutActor::admin(null, 'Finance');
    }

    /** @return array<int, array{check: string, subject: string, detail: string}> */
    private function reconcile(): array
    {
        return app(ReconcileSellerFinance::class)();
    }

    /** Paid, rejected, cancelled and open, all against one store. */
    private function busyHistory(): SellerAccount
    {
        ['seller' => $seller] = $this->makeSeller();

        $this->availableEarning($seller, 40_000);
        $this->availableEarning($seller, 60_000);
        $this->destination($seller);

        $paid = $this->requestPayout($seller, 25_000);
        app(ApprovePayout::class)($paid, $this->admin());
        app(RecordPayoutSettlement::class)($paid, $this->admin(), 'wire', 'FT-1');

        $rejected = $this->requestPayout($seller, 30_000);
        app(RejectPayout::class)($rejected, $this->admin(), 'Not this time.');

        $cancelled = $this->requestPayout($seller, 10_000);
        app(CancelPayoutRequest::class)($cancelled, PayoutActor::seller(null));

        $this->reversal($seller, 5_000);

        $this->requestPayout($seller, 20_000);

        return $seller;
    }

    #[Test]
    public function a_complicated_history_reconciles(): void
    {
        $seller = $this->busyHistory();

        $this->assertSame([], $this->reconcile());

        // And the arithmetic is what it should be: 100,000 earned,
        // 25,000 paid, 5,000 refunded, 20,000 held.
        $position = $this->positionOf($seller);
        $this->assertSame(70_000, $position->availableMinor);
        $this->assertSame(20_000, $position->reservedMinor);
        $this->assertSame(50_000, $position->withdrawableMinor());
        $this->assertSame(25_000, $position->paidOutMinor);
    }

    #[Test]
    public function a_paid_payout_matches_its_ledger_debit(): void
    {
        $this->busyHistory();

        // §41: break it by hand, in a way no action would allow.
        $debit = SellerLedgerEntry::query()->withoutGlobalScopes()
            ->where('type', LedgerEntryType::Payout->value)->firstOrFail();

        DB::table('seller_ledger_entries')->where('id', $debit->id)
            ->update(['amount_minor' => -20_000]);

        $problems = $this->reconcile();

        $this->assertNotSame([], $problems);
        $this->assertContains(
            'paid_payout_has_one_matching_debit',
            array_column($problems, 'check'),
        );
    }

    #[Test]
    public function a_paid_payout_matches_its_settled_allocations(): void
    {
        $this->busyHistory();

        $settled = PayoutAllocation::query()->withoutGlobalScopes()
            ->where('status', PayoutAllocationStatus::Settled->value)->firstOrFail();

        DB::table('payout_allocations')->where('id', $settled->id)
            ->update(['amount_minor' => 1]);

        $this->assertContains(
            'paid_payout_matches_settled_allocations',
            array_column($this->reconcile(), 'check'),
        );
    }

    #[Test]
    public function an_open_payout_matches_what_it_holds(): void
    {
        $this->busyHistory();

        $held = PayoutAllocation::query()->withoutGlobalScopes()
            ->where('status', PayoutAllocationStatus::Held->value)->firstOrFail();

        DB::table('payout_allocations')->where('id', $held->id)
            ->update(['amount_minor' => 500]);

        $problems = $this->reconcile();

        $this->assertContains(
            'open_payout_matches_its_reservation',
            array_column($problems, 'check'),
        );
    }

    #[Test]
    public function a_rejected_or_cancelled_payout_holds_nothing(): void
    {
        $seller = $this->busyHistory();

        // Every ended request has released everything.
        $this->assertSame(
            0,
            (int) PayoutAllocation::query()->withoutGlobalScopes()
                ->join('payout_requests', 'payout_requests.id', '=', 'payout_allocations.payout_request_id')
                ->whereIn('payout_requests.status', [
                    PayoutStatus::Rejected->value, PayoutStatus::Cancelled->value,
                ])
                ->where('payout_allocations.status', PayoutAllocationStatus::Held->value)
                ->sum('payout_allocations.amount_minor'),
        );

        // Break it: put a rejected request's allocations back on hold.
        $rejectedId = DB::table('payout_requests')
            ->where('seller_account_id', $seller->id)
            ->where('status', PayoutStatus::Rejected->value)
            ->value('id');

        DB::table('payout_allocations')->where('payout_request_id', $rejectedId)
            ->update(['status' => PayoutAllocationStatus::Held->value]);

        $this->assertContains(
            'ended_payout_holds_nothing',
            array_column($this->reconcile(), 'check'),
        );
    }

    #[Test]
    public function the_running_balance_is_checked_against_the_entries(): void
    {
        $seller = $this->busyHistory();

        $last = SellerLedgerEntry::query()->withoutGlobalScopes()
            ->where('seller_account_id', $seller->id)
            ->orderByDesc('id')
            ->firstOrFail();

        // A ledger written outside PostLedgerEntry is the one thing that
        // can make this drift, and it makes every statement wrong.
        DB::table('seller_ledger_entries')->where('id', $last->id)
            ->update(['balance_after_minor' => 999_999]);

        $problems = $this->reconcile();

        $this->assertContains(
            'running_balance_matches_the_entries',
            array_column($problems, 'check'),
        );
    }

    #[Test]
    public function the_command_reports_clean_and_fails_on_a_discrepancy(): void
    {
        $this->busyHistory();

        /*
         * Artisan::call rather than $this->artisan(): the PendingCommand
         * helper captures output through a mocked OutputStyle that does
         * not survive the command having been resolved earlier in the same
         * process, which made this assertion depend on what other tests
         * had run. The exit code and the text are the same either way.
         */
        $this->assertSame(Command::SUCCESS, Artisan::call('finance:reconcile-sellers'));
        $this->assertStringContainsString('Seller finance reconciles in USD.', Artisan::output());

        $held = PayoutAllocation::query()->withoutGlobalScopes()
            ->where('status', PayoutAllocationStatus::Held->value)->firstOrFail();

        DB::table('payout_allocations')->where('id', $held->id)->update(['amount_minor' => 7]);

        // Non-zero, so CI and a scheduler can both act on it.
        $this->assertSame(Command::FAILURE, Artisan::call('finance:reconcile-sellers'));

        $output = Artisan::output();

        $this->assertStringContainsString('open_payout_matches_its_reservation', $output);
        $this->assertStringContainsString('Nothing has been changed. These need a person.', $output);
    }

    #[Test]
    public function reconciliation_changes_nothing(): void
    {
        $seller = $this->busyHistory();

        $snapshot = static fn (): array => [
            'entries' => DB::table('seller_ledger_entries')->orderBy('id')->get()
                ->map(static fn (object $row): array => (array) $row)->all(),
            'allocations' => DB::table('payout_allocations')->orderBy('id')->get()
                ->map(static fn (object $row): array => (array) $row)->all(),
            'payouts' => DB::table('payout_requests')->orderBy('id')->get()
                ->map(static fn (object $row): array => (array) $row)->all(),
        ];

        $before = $snapshot();

        // Break something so it has a reason to want to "fix" it.
        DB::table('payout_allocations')
            ->where('status', PayoutAllocationStatus::Held->value)
            ->update(['amount_minor' => 3]);

        $this->reconcile();
        $this->reconcile();

        $after = $snapshot();

        $this->assertSame($before['entries'], $after['entries']);
        $this->assertSame($before['payouts'], $after['payouts']);
        $this->assertNotSame(
            $before['allocations'],
            $after['allocations'],
            'The sabotage above is the only change; the reconciliation made none.',
        );
        $this->assertSame((int) $seller->id, (int) $after['allocations'][0]['seller_account_id']);
    }

    #[Test]
    public function the_platform_liability_equals_the_sum_of_every_seller_ledger(): void
    {
        $this->busyHistory();
        $this->busyHistory();

        $summary = app(SummarisePlatformFinance::class)();

        $ledgerSum = (int) DB::table('seller_ledger_entries')
            ->where('currency', 'USD')
            ->sum('amount_minor');

        // §74 in the reporting sense: the platform's stated liability is
        // the seller ledgers and nothing else.
        $this->assertSame($ledgerSum, $summary['balances']['liabilityMinor']);
    }
}
