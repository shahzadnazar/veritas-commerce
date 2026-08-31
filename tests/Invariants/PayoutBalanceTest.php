<?php

declare(strict_types=1);

namespace Tests\Invariants;

use App\Modules\Ledger\Actions\PostLedgerEntry;
use App\Modules\Ledger\Enums\LedgerEntryStatus;
use App\Modules\Ledger\Enums\LedgerEntryType;
use App\Modules\Ledger\Models\SellerLedgerEntry;
use App\Modules\Ledger\Queries\GetSellerBalance;
use App\Modules\Payouts\Actions\RequestPayout;
use App\Modules\Payouts\Exceptions\PayoutNotPermitted;
use App\Modules\Payouts\Models\PayoutRequest;
use App\Modules\Sellers\Models\SellerAccount;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Invariant 4 — a payout can never exceed the seller's available balance,
 * and the balance is derived from the ledger rather than a stored column.
 *
 * Also covers Decision 5: an earning clears for a configured number of days
 * before it becomes withdrawable, so a refund arriving after delivery
 * cannot chase money that has already left.
 */
final class PayoutBalanceTest extends TestCase
{
    use RefreshDatabase;

    private function credit(SellerAccount $seller, int $minor, LedgerEntryStatus $status = LedgerEntryStatus::Available): SellerLedgerEntry
    {
        return app(PostLedgerEntry::class)(
            seller: $seller,
            type: LedgerEntryType::SaleEarning,
            amountMinor: $minor,
            status: $status,
            availableAt: $status === LedgerEntryStatus::Available ? now()->subDay() : null,
        );
    }

    #[Test]
    public function a_payout_above_the_available_balance_is_refused(): void
    {
        ['seller' => $seller] = $this->makeSeller();
        $this->credit($seller, 20_000);

        $this->expectException(PayoutNotPermitted::class);
        $this->expectExceptionMessage('only $200.00 is available');

        app(RequestPayout::class)($seller, 25_000);
    }

    #[Test]
    public function clearing_money_cannot_be_withdrawn(): void
    {
        ['seller' => $seller] = $this->makeSeller();

        // An earning posted today clears for the configured period first.
        app(PostLedgerEntry::class)(
            seller: $seller,
            type: LedgerEntryType::SaleEarning,
            amountMinor: 50_000,
        );

        $balance = app(GetSellerBalance::class)($seller->id);

        $this->assertSame(50_000, $balance->clearing->minor, 'The earning is clearing, not available.');
        $this->assertSame(0, $balance->available->minor);

        $this->expectException(PayoutNotPermitted::class);
        app(RequestPayout::class)($seller, 10_000);
    }

    #[Test]
    public function the_clearing_period_comes_from_configuration_not_a_literal(): void
    {
        config(['veritas.payouts.seller_clearing_period_days' => 3]);
        ['seller' => $seller] = $this->makeSeller();

        $entry = app(PostLedgerEntry::class)(
            seller: $seller,
            type: LedgerEntryType::SaleEarning,
            amountMinor: 10_000,
        );

        $this->assertTrue(
            $entry->available_at->between(now()->addDays(3)->subMinute(), now()->addDays(3)->addMinute()),
            'available_at must be derived from the configured clearing period, not a literal.',
        );
    }

    #[Test]
    public function a_seller_override_beats_the_platform_clearing_period(): void
    {
        config(['veritas.payouts.seller_clearing_period_days' => 7]);

        $seller = SellerAccount::factory()->withClearingPeriod(1)->create();

        $entry = app(PostLedgerEntry::class)(
            seller: $seller,
            type: LedgerEntryType::SaleEarning,
            amountMinor: 10_000,
        );

        $this->assertTrue(
            $entry->available_at->between(now()->addDay()->subMinute(), now()->addDay()->addMinute()),
            "The seller's own clearing period overrides the platform default.",
        );
    }

    #[Test]
    public function requesting_a_payout_holds_the_amount_out_of_available(): void
    {
        ['seller' => $seller] = $this->makeSeller();
        $this->credit($seller, 30_000);

        $request = app(RequestPayout::class)($seller, 20_000);
        $balance = app(GetSellerBalance::class)($seller->id);

        $this->assertSame(20_000, $balance->held->minor, 'The requested amount is held.');
        $this->assertSame(10_000, $balance->available->minor, 'Only the remainder stays available.');
        $this->assertStringStartsWith('PO-', $request->reference);
    }

    #[Test]
    public function a_second_open_request_is_refused(): void
    {
        ['seller' => $seller] = $this->makeSeller();
        $this->credit($seller, 40_000);

        app(RequestPayout::class)($seller, 10_000);

        $this->expectException(PayoutNotPermitted::class);
        $this->expectExceptionMessage('already has an open payout request');

        app(RequestPayout::class)($seller, 10_000);
    }

    #[Test]
    public function the_database_refuses_a_second_open_request_even_without_the_application_check(): void
    {
        ['seller' => $seller] = $this->makeSeller();

        PayoutRequest::factory()->create([
            'seller_account_id' => $seller->id,
            'status' => 'requested',
        ]);

        // Bypassing the action entirely — the partial unique index is the
        // real control, not the check in RequestPayout.
        $this->expectException(QueryException::class);

        PayoutRequest::factory()->create([
            'seller_account_id' => $seller->id,
            'status' => 'requested',
        ]);
    }

    #[Test]
    public function a_payout_below_the_minimum_is_refused(): void
    {
        ['seller' => $seller] = $this->makeSeller();
        $this->credit($seller, 100_000);

        $this->expectException(PayoutNotPermitted::class);
        $this->expectExceptionMessage('minimum payout is $50.00');

        app(RequestPayout::class)($seller, 100);
    }

    #[Test]
    public function a_suspended_seller_cannot_request_a_payout(): void
    {
        $seller = SellerAccount::factory()->suspended()->create();
        $this->credit($seller, 100_000);

        $this->expectException(PayoutNotPermitted::class);
        $this->expectExceptionMessage('Suspended store cannot request a payout');

        app(RequestPayout::class)($seller, 10_000);
    }

    #[Test]
    public function the_running_balance_matches_the_sum_of_entries(): void
    {
        ['seller' => $seller] = $this->makeSeller();

        $this->credit($seller, 10_000);
        $this->credit($seller, 5_000);
        app(PostLedgerEntry::class)(
            seller: $seller,
            type: LedgerEntryType::RefundReversal,
            amountMinor: -2_500,
        );

        $entries = SellerLedgerEntry::withoutGlobalScopes()
            ->where('seller_account_id', $seller->id)
            ->orderBy('id')
            ->get();

        $running = 0;

        foreach ($entries as $entry) {
            $running += $entry->amount_minor;
            $this->assertSame($running, $entry->balance_after_minor, 'Each row records the balance at that moment.');
        }

        $this->assertSame(12_500, $running);
    }

    #[Test]
    public function ledger_entries_cannot_be_rewritten_or_deleted(): void
    {
        ['seller' => $seller] = $this->makeSeller();
        $entry = $this->credit($seller, 10_000);

        try {
            $entry->update(['amount_minor' => 999]);
            $this->fail('Amending a ledger amount must throw.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('immutable', $exception->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('append-only');
        $entry->delete();
    }
}
