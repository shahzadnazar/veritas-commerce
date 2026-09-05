<?php

declare(strict_types=1);

namespace Tests\Feature\Payouts;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Ledger\Enums\LedgerEntryType;
use App\Modules\Ledger\Models\SellerLedgerEntry;
use App\Modules\Payouts\Actions\RejectPayout;
use App\Modules\Payouts\Data\PayoutActor;
use App\Modules\Payouts\Enums\PayoutStatus;
use App\Modules\Payouts\Models\PayoutRequest;
use App\Modules\Sellers\Models\SellerAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * §21–§27 and §33 — payout operations, and who may perform them.
 *
 * The five payout permissions are five different acts of trust, and the
 * tests below are mostly about the boundaries between them: support may
 * look, a marketplace admin may pick a request up, and only finance may
 * authorise one or say that money left.
 */
final class AdminPayoutOperationsTest extends TestCase
{
    use BuildsSellerFinance;
    use RefreshDatabase;

    /** @return array{seller: SellerAccount, payout: PayoutRequest} */
    private function pending(int $availableMinor = 100_000, int $requestMinor = 60_000): array
    {
        ['seller' => $seller] = $this->makeSeller();

        $this->availableEarning($seller, $availableMinor);
        $this->destination($seller);

        return ['seller' => $seller, 'payout' => $this->requestPayout($seller, $requestMinor)];
    }

    #[Test]
    public function the_queue_shows_each_stores_balance_beside_what_it_asked_for(): void
    {
        ['payout' => $payout] = $this->pending();

        $finance = $this->makeAdmin(AdminRole::FinanceAdmin);

        $props = $this->actingAs($finance, 'admin')
            ->get('/admin/payouts')
            ->viewData('page')['props'];

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $props['payouts'];

        $matching = array_values(array_filter(
            $rows,
            static fn (array $row): bool => $row['reference'] === $payout->reference,
        ));

        $this->assertCount(1, $matching);
        $this->assertSame('$600.00', $matching[0]['amount']);
        // 100,000 available less the 60,000 this request holds.
        $this->assertSame('$400.00', $matching[0]['sellerWithdrawable']);
        $this->assertFalse($matching[0]['sellerIsNegative']);
    }

    #[Test]
    public function the_queue_filters_by_status_and_by_store(): void
    {
        ['payout' => $open] = $this->pending();
        ['seller' => $otherSeller, 'payout' => $decided] = $this->pending();

        app(RejectPayout::class)(
            $decided,
            PayoutActor::admin(null),
            'Not this time.',
        );

        $finance = $this->makeAdmin(AdminRole::FinanceAdmin);

        $openOnly = $this->actingAs($finance, 'admin')
            ->get('/admin/payouts?status=open')
            ->viewData('page')['props'];

        $references = array_column($openOnly['payouts'], 'reference');
        $this->assertContains($open->reference, $references);
        $this->assertNotContains($decided->reference, $references);

        $byStore = $this->actingAs($finance, 'admin')
            ->get('/admin/payouts?seller='.urlencode($otherSeller->legal_name))
            ->viewData('page')['props'];

        $this->assertSame([$decided->reference], array_column($byStore['payouts'], 'reference'));
    }

    #[Test]
    public function finance_can_review_approve_and_settle(): void
    {
        ['seller' => $seller, 'payout' => $payout] = $this->pending();

        $finance = $this->makeAdmin(AdminRole::FinanceAdmin);

        $this->actingAs($finance, 'admin')
            ->post("/admin/payouts/{$payout->reference}/review")
            ->assertRedirect();

        $this->assertSame(PayoutStatus::UnderReview, $payout->refresh()->status);

        $this->actingAs($finance, 'admin')
            ->post("/admin/payouts/{$payout->reference}/approve", ['note' => 'Looks fine.'])
            ->assertRedirect();

        $this->assertSame(PayoutStatus::Approved, $payout->refresh()->status);
        $this->assertSame((int) $finance->id, (int) $payout->approved_by_admin_id);

        // Approval is not payment: no debit, and the money is still held.
        $this->assertSame(
            0,
            SellerLedgerEntry::query()->withoutGlobalScopes()
                ->where('type', LedgerEntryType::Payout->value)->count(),
        );
        $this->assertSame(60_000, $this->positionOf($seller)->reservedMinor);

        $this->actingAs($finance, 'admin')
            ->post("/admin/payouts/{$payout->reference}/settle", [
                'method' => 'wire',
                'reference' => 'FT-2026-771',
            ])
            ->assertRedirect();

        $settled = $payout->refresh();

        $this->assertSame(PayoutStatus::Paid, $settled->status);
        $this->assertSame('FT-2026-771', $settled->settlement_ref);
        $this->assertNotNull($settled->paid_at);
        $this->assertSame((int) $finance->id, (int) $settled->settled_by_admin_id);

        $position = $this->positionOf($seller);
        $this->assertSame(0, $position->reservedMinor);
        $this->assertSame(40_000, $position->withdrawableMinor());
        $this->assertSame(60_000, $position->paidOutMinor);
    }

    #[Test]
    public function settlement_without_a_reference_is_refused(): void
    {
        ['payout' => $payout] = $this->pending();

        $finance = $this->makeAdmin(AdminRole::FinanceAdmin);

        $this->actingAs($finance, 'admin')
            ->post("/admin/payouts/{$payout->reference}/approve");

        $this->actingAs($finance, 'admin')
            ->post("/admin/payouts/{$payout->reference}/settle", ['method' => 'wire'])
            ->assertSessionHasErrors('reference');

        $this->assertSame(PayoutStatus::Approved, $payout->refresh()->status);
    }

    #[Test]
    public function rejection_requires_a_reason_and_gives_the_money_back(): void
    {
        ['seller' => $seller, 'payout' => $payout] = $this->pending();

        $finance = $this->makeAdmin(AdminRole::FinanceAdmin);

        $this->actingAs($finance, 'admin')
            ->post("/admin/payouts/{$payout->reference}/reject")
            ->assertSessionHasErrors('reason');

        $this->assertSame(PayoutStatus::Requested, $payout->refresh()->status);

        $this->actingAs($finance, 'admin')
            ->post("/admin/payouts/{$payout->reference}/reject", [
                'reason' => 'The store name does not match the destination.',
            ])
            ->assertRedirect();

        $rejected = $payout->refresh();

        $this->assertSame(PayoutStatus::Rejected, $rejected->status);
        $this->assertSame('The store name does not match the destination.', $rejected->decision_reason);
        $this->assertSame(100_000, $this->positionOf($seller)->withdrawableMinor());
    }

    #[Test]
    public function support_may_look_and_may_not_decide(): void
    {
        ['payout' => $payout] = $this->pending();

        $support = $this->makeAdmin(AdminRole::Support);

        $this->actingAs($support, 'admin')->get('/admin/payouts')->assertOk();
        $this->actingAs($support, 'admin')->get("/admin/payouts/{$payout->reference}")->assertOk();

        foreach (['review', 'approve', 'reject', 'settle', 'fail', 'cancel'] as $action) {
            $this->actingAs($support, 'admin')
                ->post("/admin/payouts/{$payout->reference}/{$action}", [
                    'reason' => 'x', 'method' => 'wire', 'reference' => 'y',
                ])
                ->assertForbidden();
        }

        $this->assertSame(PayoutStatus::Requested, $payout->refresh()->status);
    }

    #[Test]
    public function an_analyst_sees_totals_and_cannot_settle(): void
    {
        ['payout' => $payout] = $this->pending();

        $analyst = $this->makeAdmin(AdminRole::Analyst);

        $this->actingAs($analyst, 'admin')->get('/admin/payouts')->assertOk();

        $this->actingAs($analyst, 'admin')
            ->post("/admin/payouts/{$payout->reference}/settle", [
                'method' => 'wire', 'reference' => 'FT-1',
            ])
            ->assertForbidden();

        // And no destination metadata reaches them.
        $props = $this->actingAs($analyst, 'admin')
            ->get("/admin/payouts/{$payout->reference}")
            ->viewData('page')['props'];

        $this->assertFalse($props['can']['viewSensitive']);
        $this->assertArrayNotHasKey('destination', $props['payout']);
    }

    #[Test]
    public function a_marketplace_admin_may_start_a_review_and_go_no_further(): void
    {
        ['payout' => $payout] = $this->pending();

        $admin = $this->makeAdmin(AdminRole::MarketplaceAdmin);

        $this->actingAs($admin, 'admin')
            ->post("/admin/payouts/{$payout->reference}/review")
            ->assertRedirect();

        $this->assertSame(PayoutStatus::UnderReview, $payout->refresh()->status);

        $this->actingAs($admin, 'admin')
            ->post("/admin/payouts/{$payout->reference}/approve")
            ->assertForbidden();
    }

    #[Test]
    public function finance_sees_the_destination_and_when_it_changed(): void
    {
        ['seller' => $seller, 'payout' => $payout] = $this->pending();

        $finance = $this->makeAdmin(AdminRole::FinanceAdmin);

        $props = $this->actingAs($finance, 'admin')
            ->get("/admin/payouts/{$payout->reference}")
            ->viewData('page')['props'];

        $this->assertTrue($props['can']['viewSensitive']);
        $this->assertNotNull($props['payout']['destination']);
        $this->assertSame('manual', $props['payout']['destination']['type']);
        $this->assertSame((string) $seller->public_id, (string) $props['seller']['id']);
    }

    #[Test]
    public function a_failed_settlement_is_visible_and_keeps_the_money_held(): void
    {
        ['seller' => $seller, 'payout' => $payout] = $this->pending();

        $finance = $this->makeAdmin(AdminRole::FinanceAdmin);

        $this->actingAs($finance, 'admin')->post("/admin/payouts/{$payout->reference}/approve");

        $this->actingAs($finance, 'admin')
            ->post("/admin/payouts/{$payout->reference}/fail", [
                'reason' => 'Beneficiary bank returned it.',
                'failure_code' => 'returned',
            ])
            ->assertRedirect();

        $this->assertSame(PayoutStatus::Failed, $payout->refresh()->status);
        $this->assertSame(60_000, $this->positionOf($seller)->reservedMinor);

        $props = $this->actingAs($finance, 'admin')
            ->get("/admin/payouts/{$payout->reference}")
            ->viewData('page')['props'];

        $this->assertCount(1, $props['payout']['settlementAttempts']);
        $this->assertSame('failed', $props['payout']['settlementAttempts'][0]['status']);

        // Ending it deliberately is what releases the hold.
        $this->actingAs($finance, 'admin')
            ->post("/admin/payouts/{$payout->reference}/cancel", ['reason' => 'Abandoned.'])
            ->assertRedirect();

        $this->assertSame(100_000, $this->positionOf($seller)->withdrawableMinor());
    }

    #[Test]
    public function every_decision_is_audited_with_its_reason(): void
    {
        ['payout' => $payout] = $this->pending();

        $finance = $this->makeAdmin(AdminRole::FinanceAdmin);

        $this->actingAs($finance, 'admin')->post("/admin/payouts/{$payout->reference}/review");
        $this->actingAs($finance, 'admin')->post("/admin/payouts/{$payout->reference}/approve");
        $this->actingAs($finance, 'admin')->post("/admin/payouts/{$payout->reference}/settle", [
            'method' => 'wire', 'reference' => 'FT-9',
        ]);

        foreach (['payouts.review.started', 'payouts.approved', 'payouts.settled'] as $action) {
            $log = AuditLog::query()->where('action', $action)->firstOrFail();

            $this->assertSame('admin', $log->actor_type);
            $this->assertSame((int) $finance->id, (int) $log->actor_id);

            /** @var array<string, mixed> $changes */
            $changes = $log->changes ?? [];

            $this->assertSame($payout->reference, $changes['reference'] ?? null);
        }

        $settled = AuditLog::query()->where('action', 'payouts.settled')->firstOrFail();
        $this->assertSame('FT-9', $settled->reason);
    }

    #[Test]
    public function a_finance_admin_can_post_an_audited_adjustment(): void
    {
        ['seller' => $seller] = $this->pending();

        $finance = $this->makeAdmin(AdminRole::FinanceAdmin);

        $this->actingAs($finance, 'admin')
            ->post("/admin/sellers/{$seller->public_id}/finance/adjust", [
                'amount_minor' => -2_500,
                'reason' => 'Duplicate commission corrected.',
            ])
            ->assertRedirect();

        // A debit bites immediately: 100,000 available less 60,000 held
        // less the 2,500 correction.
        $position = $this->positionOf($seller);
        $this->assertSame(97_500, $position->availableMinor);
        $this->assertSame(37_500, $position->withdrawableMinor());

        $log = AuditLog::query()->where('action', 'finance.adjustment.debit')->firstOrFail();
        $this->assertSame('Duplicate commission corrected.', $log->reason);
    }

    #[Test]
    public function an_adjustment_credit_waits_out_the_clearing_period(): void
    {
        ['seller' => $seller] = $this->pending();

        $finance = $this->makeAdmin(AdminRole::FinanceAdmin);

        $this->actingAs($finance, 'admin')
            ->post("/admin/sellers/{$seller->public_id}/finance/adjust", [
                'amount_minor' => 5_000,
                'reason' => 'Goodwill after the outage.',
            ])
            ->assertRedirect();

        // §65: a credit must not be a way past the clearing window.
        $position = $this->positionOf($seller);
        $this->assertSame(5_000, $position->clearingMinor);
        $this->assertSame(100_000, $position->availableMinor);
        $this->assertSame(40_000, $position->withdrawableMinor());
    }

    #[Test]
    public function only_finance_may_adjust_a_sellers_balance(): void
    {
        ['seller' => $seller] = $this->pending();

        foreach ([AdminRole::Support, AdminRole::Analyst, AdminRole::MarketplaceAdmin] as $role) {
            $this->actingAs($this->makeAdmin($role), 'admin')
                ->post("/admin/sellers/{$seller->public_id}/finance/adjust", [
                    'amount_minor' => 100_000,
                    'reason' => 'Trying it on.',
                ])
                ->assertForbidden();
        }

        $this->assertSame(100_000, $this->positionOf($seller)->availableMinor);
    }
}
