<?php

declare(strict_types=1);

namespace Tests\Feature\Fulfilment;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Commission\Models\CommissionRule;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Enums\ShipmentStatus;
use App\Modules\Orders\Models\ShipmentStatusHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsCommerceFixtures;
use Tests\Feature\Orders\BuildsPlacedOrders;
use Tests\Feature\Payments\BuildsPayableOrders;
use Tests\TestCase;

/**
 * The platform's fulfilment screens, and the two things staff may change.
 *
 * Recording a delivery and correcting a tracking number are both the
 * platform contradicting a seller's own record of their own shipment. Each
 * takes its own permission and a written reason, and each runs the domain
 * action a seller would — there is no separate admin path that could put a
 * parcel somewhere the domain has no route to, and no "set status"
 * dropdown at all.
 */
final class AdminFulfilmentTest extends TestCase
{
    use BuildsCommerceFixtures;
    use BuildsFulfilableOrders;
    use BuildsPayableOrders;
    use BuildsPlacedOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CommissionRule::factory()->create();
    }

    #[Test]
    public function the_queue_shows_fulfilment_work_and_hides_unpaid_orders(): void
    {
        ['offer' => $paidOffer] = $this->sellableOffer(title: 'Kettle', priceMinor: 4_000, stock: 10);
        ['offer' => $unpaidOffer] = $this->sellableOffer(title: 'Grinder', priceMinor: 9_000, stock: 10);

        $paid = $this->placeOrder([[$paidOffer, 1]]);
        $this->payFor($paid);

        $this->placeOrder([[$unpaidOffer, 1]]);

        $this->asAdmin($this->makeAdmin(AdminRole::MarketplaceAdmin))
            ->get('/admin/fulfilment')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Fulfilment/Index')
                ->has('orders.data', 1)
                ->where('orders.data.0.status', 'paid'));
    }

    #[Test]
    public function the_detail_shows_the_whole_hierarchy_and_the_clearing_schedule(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 10_000, stock: 10);
        $order = $this->placeOrder([[$offer, 1]]);

        $this->payFor($order);

        $sellerOrder = $this->sellerOrderFor($order->id);
        $this->deliver($this->shipEverything($sellerOrder));

        $this->asAdmin($this->makeAdmin(AdminRole::FinanceAdmin))
            ->get("/admin/fulfilment/{$sellerOrder->reference}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Fulfilment/Show')
                ->where('sellerOrder.status', 'delivered')
                ->where('parent.summary.state', 'delivered')
                ->has('fulfilment.shipments', 1)
                ->has('fulfilment.shipments.0.history')
                ->where('fulfilment.shipments.0.status', 'delivered')
                ->where('can.viewClearing', true)
                // The money, from the ledger, with its release date.
                ->has('earnings', 1)
                ->where('earnings.0.status', 'clearing')
                ->where('earnings.0.amountMinor', 8_800)
                ->has('earnings.0.availableAt'));
    }

    #[Test]
    public function an_admin_records_a_delivery_with_a_reason_and_it_lands_in_the_history(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 10);
        $order = $this->placeOrder([[$offer, 1]]);

        $this->payFor($order);

        $sellerOrder = $this->sellerOrderFor($order->id);
        $shipment = $this->shipEverything($sellerOrder);
        $admin = $this->makeAdmin(AdminRole::MarketplaceAdmin);

        $parcel = "/admin/fulfilment/{$sellerOrder->reference}/shipments/{$shipment->public_id}";

        // A reason is required by the server, not only by the dialog.
        $this->asAdmin($admin)->post("{$parcel}/deliver")->assertSessionHasErrors('reason');

        $this->asAdmin($admin)->post("{$parcel}/deliver", [
            'reason' => 'Customer confirmed receipt by phone; the carrier never scanned it.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(ShipmentStatus::Delivered, $shipment->refresh()->status);
        $this->assertNotNull($sellerOrder->refresh()->earnings_clear_at);

        $history = ShipmentStatusHistory::query()
            ->where('shipment_id', $shipment->id)
            ->where('to_status', ShipmentStatus::Delivered->value)
            ->firstOrFail();

        $this->assertSame('admin', $history->actor_type);
        $this->assertStringContainsString('confirmed receipt by phone', (string) $history->reason);

        $log = AuditLog::query()->where('action', 'fulfilment.override.delivered')->firstOrFail();

        $this->assertSame('admin', $log->actor_type);
        $this->assertStringContainsString('confirmed receipt by phone', (string) $log->reason);
    }

    #[Test]
    public function only_the_permission_opens_an_override(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 10);
        $order = $this->placeOrder([[$offer, 1]]);

        $this->payFor($order);

        $sellerOrder = $this->sellerOrderFor($order->id);
        $shipment = $this->shipEverything($sellerOrder);
        $parcel = "/admin/fulfilment/{$sellerOrder->reference}/shipments/{$shipment->public_id}";

        $body = ['reason' => 'Trying to record a delivery without the authority to.'];

        // Support and finance read fulfilment and do not move parcels.
        foreach ([AdminRole::Support, AdminRole::FinanceAdmin, AdminRole::SellerOperations] as $role) {
            $this->asAdmin($this->makeAdmin($role))
                ->post("{$parcel}/deliver", $body)
                ->assertForbidden();
        }

        // Analyst cannot even open the screens.
        $analyst = $this->makeAdmin(AdminRole::Analyst);

        $this->asAdmin($analyst)->get('/admin/fulfilment')->assertForbidden();
        $this->asAdmin($analyst)->get("/admin/fulfilment/{$sellerOrder->reference}")->assertForbidden();

        $this->assertSame(ShipmentStatus::Shipped, $shipment->refresh()->status);
        $this->assertNull($sellerOrder->refresh()->earnings_clear_at);
    }

    #[Test]
    public function support_reads_fulfilment_without_the_clearing_schedule(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 10_000, stock: 10);
        $order = $this->placeOrder([[$offer, 1]]);

        $this->payFor($order);

        $sellerOrder = $this->sellerOrderFor($order->id);
        $this->deliver($this->shipEverything($sellerOrder));

        $this->asAdmin($this->makeAdmin(AdminRole::Support))
            ->get("/admin/fulfilment/{$sellerOrder->reference}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('can.override', false)
                ->where('can.correctTracking', false)
                ->where('can.viewClearing', false)
                // Not hidden in the UI: the ledger is never read for them.
                ->where('earnings', []));
    }

    #[Test]
    public function a_delivered_parcels_tracking_is_correctable_only_here_and_only_with_a_reason(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 10);
        $order = $this->placeOrder([[$offer, 1]]);

        $this->payFor($order);

        $sellerOrder = $this->sellerOrderFor($order->id);
        $shipment = $this->shipEverything($sellerOrder);
        $this->deliver($shipment);

        $admin = $this->makeAdmin(AdminRole::MarketplaceAdmin);
        $parcel = "/admin/fulfilment/{$sellerOrder->reference}/shipments/{$shipment->public_id}";

        $this->asAdmin($admin)->post("{$parcel}/tracking", [
            'carrier' => 'ups',
            'tracking_number' => '1Z999AA10123456784',
        ])->assertSessionHasErrors('reason');

        $this->asAdmin($admin)->post("{$parcel}/tracking", [
            'carrier' => 'ups',
            'tracking_number' => '1Z999AA10123456784',
            'reason' => 'Recorded against the wrong carrier at dispatch; corrected from the manifest.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('UPS', $shipment->refresh()->carrier_name);

        // The number the customer was originally given is still readable.
        $this->assertSame(
            1,
            ShipmentStatusHistory::query()
                ->where('shipment_id', $shipment->id)
                ->where('tracking_number', '9400100000012345678901')
                ->where('actor_type', 'admin')
                ->count(),
        );
    }

    #[Test]
    public function the_screens_are_closed_to_sellers_and_customers(): void
    {
        ['offer' => $offer, 'seller' => $seller] = $this->sellableOffer(priceMinor: 4_000, stock: 10);
        $order = $this->placeOrder([[$offer, 1]]);

        $this->payFor($order);

        $sellerOrder = $this->sellerOrderFor($order->id);

        $this->get('/admin/fulfilment')->assertRedirect('/admin/login');
        $this->get("/admin/fulfilment/{$sellerOrder->reference}")->assertRedirect('/admin/login');

        $this->asUser(User::factory()->create())
            ->get('/admin/fulfilment')
            ->assertRedirect('/admin/login');

        $this->asAdmin($this->makeAdmin(AdminRole::MarketplaceAdmin))
            ->get('/admin/fulfilment')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
