<?php

declare(strict_types=1);

namespace Tests\Feature\Payouts;

use App\Modules\Commission\Models\CommissionRule;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Orders\Actions\CompleteDeliveredSellerOrders;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Payments\Actions\RequestRefund;
use App\Modules\Payouts\Actions\ApprovePayout;
use App\Modules\Payouts\Actions\RecordPayoutSettlement;
use App\Modules\Payouts\Data\PayoutActor;
use App\Modules\Payouts\Queries\SummarisePlatformFinance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsCommerceFixtures;
use Tests\Feature\Fulfilment\BuildsFulfilableOrders;
use Tests\Feature\Orders\BuildsPlacedOrders;
use Tests\Feature\Payments\BuildsPayableOrders;
use Tests\TestCase;

/**
 * §37, §38 and §39 — the platform's own figures, and what each word means.
 *
 * Every number below is checked against a hand-worked example rather than
 * against the code that produced it, because a reporting test that agrees
 * with its implementation by construction proves nothing.
 *
 * The commission assertions matter most: §37 forbids computing platform
 * revenue from the current commission configuration, so one of these
 * changes the rate afterwards and shows the figure does not move.
 */
final class PlatformFinanceTest extends TestCase
{
    use BuildsCommerceFixtures;
    use BuildsFulfilableOrders;
    use BuildsPayableOrders;
    use BuildsPlacedOrders;
    use BuildsSellerFinance;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CommissionRule::factory()->create(['rate_percent' => '12.00']);
    }

    #[Test]
    public function gmv_commission_and_earnings_come_from_the_immutable_records(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 10_000, stock: 5);
        $order = $this->placeOrder([[$offer, 2]]);

        $this->payFor($order);

        $summary = app(SummarisePlatformFinance::class)();

        // Two kettles at $100. Commission at 12% is $24, the seller's
        // share $176, and the customer paid $200.
        $this->assertSame(20_000, $summary['flows']['gmvMinor']);
        $this->assertSame('$200.00', $summary['flows']['gmv']);
        $this->assertSame(0, $summary['flows']['refundsMinor']);
        $this->assertSame(20_000, $summary['flows']['netSalesMinor']);
        $this->assertSame(2_400, $summary['flows']['commissionMinor']);
        $this->assertSame(17_600, $summary['flows']['sellerEarningsMinor']);
        $this->assertSame(0, $summary['flows']['payoutsPaidMinor']);

        // Nothing delivered, so it is all a pending liability.
        $this->assertSame(17_600, $summary['balances']['pendingMinor']);
        $this->assertSame(17_600, $summary['balances']['liabilityMinor']);
    }

    #[Test]
    public function commission_does_not_move_when_the_rate_changes(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 10_000, stock: 5);
        $this->payFor($this->placeOrder([[$offer, 1]]));

        $before = app(SummarisePlatformFinance::class)()['flows']['commissionMinor'];
        $this->assertSame(1_200, $before);

        // §37: the platform's revenue is what it recognised at the time,
        // not what today's rate would have produced.
        CommissionRule::query()->update(['rate_percent' => '30.00']);

        $this->assertSame(
            1_200,
            app(SummarisePlatformFinance::class)()['flows']['commissionMinor'],
            'A rate change must not rewrite a past figure.',
        );
    }

    #[Test]
    public function a_refund_reduces_net_sales_and_commission_but_not_gmv(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 10_000, stock: 5);
        $order = $this->placeOrder([[$offer, 1]]);

        $this->payFor($order);

        $item = OrderItem::query()->firstOrFail();

        app(RequestRefund::class)(
            order: $order->refresh(),
            lines: [['order_item_id' => (int) $item->id, 'amount_minor' => 10_000, 'quantity' => 1]],
            reason: 'Changed their mind.',
        );

        $summary = app(SummarisePlatformFinance::class)();

        // §38: GMV is what customers paid, before refunds. Net sales is
        // after. Commission nets its own reversal.
        $this->assertSame(10_000, $summary['flows']['gmvMinor']);
        $this->assertSame(10_000, $summary['flows']['refundsMinor']);
        $this->assertSame(0, $summary['flows']['netSalesMinor']);
        $this->assertSame(0, $summary['flows']['commissionMinor']);
        $this->assertSame(0, $summary['flows']['sellerEarningsMinor']);
        $this->assertSame(0, $summary['balances']['liabilityMinor']);
    }

    #[Test]
    public function a_settled_payout_shows_as_paid_and_reduces_the_liability(): void
    {
        ['offer' => $offer, 'seller' => $seller] = $this->sellableOffer(priceMinor: 10_000, stock: 5);
        $order = $this->placeOrder([[$offer, 1]]);

        $this->payFor($order);
        $this->deliver($this->shipEverything($this->sellerOrderFor($order->id)));

        $this->travel(8)->days();
        app(CompleteDeliveredSellerOrders::class)();

        $this->destination($seller);

        $payout = $this->requestPayout($seller, 8_800);

        $open = app(SummarisePlatformFinance::class)();
        $this->assertSame(8_800, $open['balances']['reservedMinor']);
        $this->assertSame(8_800, $open['balances']['openPayoutsMinor']);
        $this->assertSame(8_800, $open['balances']['liabilityMinor'], 'Still owed while merely requested.');

        app(ApprovePayout::class)($payout, PayoutActor::admin(null));
        app(RecordPayoutSettlement::class)($payout, PayoutActor::admin(null), 'wire', 'FT-1');

        $settled = app(SummarisePlatformFinance::class)();

        $this->assertSame(8_800, $settled['flows']['payoutsPaidMinor']);
        $this->assertSame(0, $settled['balances']['reservedMinor']);
        $this->assertSame(0, $settled['balances']['openPayoutsMinor']);
        $this->assertSame(0, $settled['balances']['liabilityMinor'], 'Paid, so no longer owed.');
    }

    #[Test]
    public function the_date_range_is_respected(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 10_000, stock: 10);

        $this->travelTo(Carbon::parse('2026-03-10 12:00:00', 'UTC'));
        $this->payFor($this->placeOrder([[$offer, 1]]));

        $this->travelTo(Carbon::parse('2026-04-10 12:00:00', 'UTC'));
        $this->payFor($this->placeOrder([[$offer, 1]]));

        $march = app(SummarisePlatformFinance::class)(
            Carbon::parse('2026-03-01', 'UTC'),
            Carbon::parse('2026-03-31 23:59:59', 'UTC'),
        );

        $both = app(SummarisePlatformFinance::class)(
            Carbon::parse('2026-03-01', 'UTC'),
            Carbon::parse('2026-04-30 23:59:59', 'UTC'),
        );

        $this->assertSame(10_000, $march['flows']['gmvMinor']);
        $this->assertSame(20_000, $both['flows']['gmvMinor']);
    }

    #[Test]
    public function currencies_are_never_added_together(): void
    {
        ['seller' => $seller] = $this->makeSeller();

        $this->availableEarning($seller, 10_000, 'USD');
        $this->availableEarning($seller, 40_000, 'EUR');

        $usd = app(SummarisePlatformFinance::class)(currency: 'USD');
        $eur = app(SummarisePlatformFinance::class)(currency: 'EUR');

        // §71: one currency per report, and it says which.
        $this->assertSame('USD', $usd['currency']);
        $this->assertSame(10_000, $usd['balances']['liabilityMinor']);
        $this->assertSame('EUR', $eur['currency']);
        $this->assertSame(40_000, $eur['balances']['liabilityMinor']);
    }

    #[Test]
    public function the_dashboard_reads_dates_in_the_platform_timezone(): void
    {
        config(['app.timezone' => 'America/New_York']);

        ['offer' => $offer] = $this->sellableOffer(priceMinor: 10_000, stock: 5);

        // 01:00 UTC on the 11th is still the 10th in New York, so a report
        // for the 10th must include it. §70: the browser is never asked.
        $this->travelTo(Carbon::parse('2026-03-11 01:00:00', 'UTC'));
        $this->payFor($this->placeOrder([[$offer, 1]]));

        $finance = $this->makeAdmin(AdminRole::FinanceAdmin);

        $props = $this->actingAs($finance, 'admin')
            ->get('/admin/finance?from=2026-03-10&to=2026-03-10')
            ->viewData('page')['props'];

        $this->assertSame(10_000, $props['summary']['flows']['gmvMinor']);
        $this->assertSame('America/New_York', $props['filters']['timezone']);
    }

    #[Test]
    public function the_dashboard_lists_stores_carrying_a_negative_balance(): void
    {
        ['seller' => $seller] = $this->makeSeller();

        // A payout that left, and a refund behind it.
        $this->availableEarning($seller, 9_000);
        $this->destination($seller);

        $payout = $this->requestPayout($seller, 9_000);
        app(ApprovePayout::class)($payout, PayoutActor::admin(null));
        app(RecordPayoutSettlement::class)($payout, PayoutActor::admin(null), 'wire', 'FT-2');

        $this->reversal($seller, 5_000);

        // Something coming that will partly offset it, so the operational
        // view can show what is going to happen without being asked.
        $this->clearingEarning($seller, 2_000);

        $finance = $this->makeAdmin(AdminRole::FinanceAdmin);

        $props = $this->actingAs($finance, 'admin')
            ->get('/admin/finance')
            ->viewData('page')['props'];

        $this->assertCount(1, $props['negativeSellers']);
        $row = $props['negativeSellers'][0];

        $this->assertSame((int) $seller->id, $row['sellerAccountId']);
        // 9,000 earned, 9,000 paid out, 5,000 refunded, 2,000 clearing.
        $this->assertSame(-3_000, $row['netMinor']);
        $this->assertSame('-$30.00', $row['net']);
        $this->assertSame(2_000, $row['incomingMinor'], 'What will offset it.');
    }

    #[Test]
    public function only_roles_with_earnings_visibility_reach_the_dashboard(): void
    {
        $this->actingAs($this->makeAdmin(AdminRole::FinanceAdmin), 'admin')
            ->get('/admin/finance')->assertOk();

        foreach ([AdminRole::Support, AdminRole::Analyst, AdminRole::SellerOperations] as $role) {
            $this->actingAs($this->makeAdmin($role), 'admin')
                ->get('/admin/finance')->assertForbidden();
        }
    }
}
