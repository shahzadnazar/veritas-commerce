<?php

declare(strict_types=1);

namespace Tests\Feature\Fulfilment;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Commission\Models\CommissionRule;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Enums\SellerOrderStatus;
use App\Modules\Orders\Enums\ShipmentStatus;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\Shipment;
use App\Modules\Payments\Models\Refund;
use App\Modules\Sellers\Enums\SellerRole;
use App\Modules\Sellers\Models\SellerMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsCommerceFixtures;
use Tests\Feature\Orders\BuildsPlacedOrders;
use Tests\Feature\Payments\BuildsPayableOrders;
use Tests\TestCase;

/**
 * The seller's fulfilment screen, over HTTP.
 *
 * What matters here is not that the buttons work but that the server
 * refuses everything the screen would never offer: a warehouse account
 * belonging to a different seller, an order that has not been paid for, a
 * parcel identifier lifted from somebody else's page, and a role whose job
 * is not to pack boxes.
 */
final class SellerFulfilmentHttpTest extends TestCase
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

    /** @return array{user: User} */
    private function member(int $sellerId, SellerRole $role = SellerRole::Owner): array
    {
        $user = User::factory()->create();

        SellerMembership::factory()->create([
            'seller_account_id' => $sellerId,
            'user_id' => $user->id,
            'role' => $role->value,
        ]);

        return ['user' => $user];
    }

    #[Test]
    public function a_seller_walks_an_order_from_paid_to_delivered(): void
    {
        ['offer' => $offer, 'seller' => $seller] = $this->sellableOffer(priceMinor: 4_000, stock: 10);
        ['user' => $member] = $this->member($seller->id);

        $order = $this->placeOrder([[$offer, 2]]);
        $this->payFor($order);

        $sellerOrder = $this->sellerOrderFor($order->id);
        $item = OrderItem::query()->where('seller_order_id', $sellerOrder->id)->firstOrFail();
        $base = "/seller/orders/{$sellerOrder->reference}";

        $this->asUser($member)->get($base)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('fulfilment.canConfirm', true)
                ->where('fulfilment.canPack', false)
                ->where('fulfilment.deliveryIsManual', true)
                ->where('fulfilment.remainingUnits', 2)
                ->where('fulfilment.items.0.remainingToShip', 2));

        $this->asUser($member)->post("{$base}/confirm")->assertRedirect();
        $this->assertSame(SellerOrderStatus::Confirmed, $sellerOrder->refresh()->status);

        $this->asUser($member)->post("{$base}/process")->assertRedirect();
        $this->assertSame(SellerOrderStatus::Processing, $sellerOrder->refresh()->status);

        $this->asUser($member)->post("{$base}/shipments", [
            'lines' => [['order_item_id' => (int) $item->id, 'quantity' => 2]],
            'carrier' => 'ups',
            'tracking_number' => '1Z999AA10123456784',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(SellerOrderStatus::Packed, $sellerOrder->refresh()->status);

        $parcel = Shipment::query()->firstOrFail();

        $this->assertSame("{$sellerOrder->reference}-S01", $parcel->reference);
        $this->assertSame('UPS', $parcel->carrier_name);
        $this->assertSame(
            'https://www.ups.com/track?tracknum=1Z999AA10123456784',
            $parcel->tracking_url,
        );

        $this->asUser($member)->post("{$base}/shipments/{$parcel->public_id}/ship")->assertRedirect();
        $this->assertSame(SellerOrderStatus::Shipped, $sellerOrder->refresh()->status);

        $this->asUser($member)->post("{$base}/shipments/{$parcel->public_id}/deliver")->assertRedirect();
        $this->assertSame(SellerOrderStatus::Delivered, $sellerOrder->refresh()->status);
        $this->assertNotNull($sellerOrder->earnings_clear_at);

        // Every step is on the record.
        $actions = AuditLog::query()->where('actor_type', 'seller')->pluck('action')->all();

        foreach ([
            'fulfilment.confirmed', 'fulfilment.processing',
            'fulfilment.shipment_created', 'fulfilment.shipped', 'fulfilment.delivered',
        ] as $action) {
            $this->assertContains($action, $actions);
        }
    }

    #[Test]
    public function an_unpaid_order_offers_nothing_and_accepts_nothing(): void
    {
        ['offer' => $offer, 'seller' => $seller] = $this->sellableOffer(priceMinor: 4_000, stock: 10);
        ['user' => $member] = $this->member($seller->id);

        $order = $this->placeOrder([[$offer, 1]]);
        $sellerOrder = $this->sellerOrderFor($order->id);
        $item = OrderItem::query()->where('seller_order_id', $sellerOrder->id)->firstOrFail();
        $base = "/seller/orders/{$sellerOrder->reference}";

        $this->asUser($member)->get($base)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('fulfilment.actionable', false)
                ->where('fulfilment.canConfirm', false)
                ->where('fulfilment.reason', 'This order cannot be packed or shipped until payment is confirmed.'));

        // And the server refuses regardless of what the screen offered.
        $this->asUser($member)->post("{$base}/confirm")->assertSessionHasErrors('fulfilment');

        $this->asUser($member)->post("{$base}/shipments", [
            'lines' => [['order_item_id' => (int) $item->id, 'quantity' => 1]],
        ])->assertSessionHasErrors('fulfilment');

        $this->assertSame(0, Shipment::query()->count());
        $this->assertSame(SellerOrderStatus::PendingPayment, $sellerOrder->refresh()->status);
    }

    #[Test]
    public function a_seller_cannot_touch_another_sellers_order_or_parcel(): void
    {
        ['offer' => $a, 'seller' => $sellerA] = $this->sellableOffer(title: 'Kettle', priceMinor: 4_000, stock: 5);
        ['offer' => $b, 'seller' => $sellerB] = $this->sellableOffer(title: 'Grinder', priceMinor: 9_000, stock: 5);

        ['user' => $memberA] = $this->member($sellerA->id);

        $order = $this->placeOrder([[$a, 1], [$b, 1]]);
        $this->payFor($order);

        $orderB = $this->sellerOrderFor($order->id, $sellerB->id);
        $parcelB = $this->shipEverything($orderB);

        $theirs = "/seller/orders/{$orderB->reference}";

        // Reading, and every write, on their order.
        $this->asUser($memberA)->get($theirs)->assertNotFound();
        $this->asUser($memberA)->post("{$theirs}/confirm")->assertNotFound();
        $this->asUser($memberA)->post("{$theirs}/shipments", [
            'lines' => [['order_item_id' => 1, 'quantity' => 1]],
        ])->assertNotFound();
        $this->asUser($memberA)->post("{$theirs}/shipments/{$parcelB->public_id}/ship")->assertNotFound();
        $this->asUser($memberA)->post("{$theirs}/shipments/{$parcelB->public_id}/deliver")->assertNotFound();

        // And their parcel id posted at A's own order: a valid identifier
        // in the wrong place is still nothing.
        $orderA = $this->sellerOrderFor($order->id, $sellerA->id);
        $mine = "/seller/orders/{$orderA->reference}";

        $this->asUser($memberA)->post("{$mine}/shipments/{$parcelB->public_id}/deliver")->assertNotFound();
        $this->asUser($memberA)->post("{$mine}/shipments/{$parcelB->public_id}/tracking", [
            'carrier' => 'ups',
            'tracking_number' => '1Z999AA10123456784',
        ])->assertNotFound();

        $this->assertSame(ShipmentStatus::Shipped, $parcelB->refresh()->status);
    }

    #[Test]
    public function a_seller_cannot_put_another_sellers_item_in_their_own_parcel(): void
    {
        ['offer' => $a, 'seller' => $sellerA] = $this->sellableOffer(title: 'Kettle', priceMinor: 4_000, stock: 5);
        ['offer' => $b, 'seller' => $sellerB] = $this->sellableOffer(title: 'Grinder', priceMinor: 9_000, stock: 5);

        ['user' => $memberA] = $this->member($sellerA->id);

        $order = $this->placeOrder([[$a, 1], [$b, 1]]);
        $this->payFor($order);

        $orderA = $this->sellerOrderFor($order->id, $sellerA->id);
        $orderB = $this->sellerOrderFor($order->id, $sellerB->id);
        $itemB = OrderItem::query()->where('seller_order_id', $orderB->id)->firstOrFail();

        $base = "/seller/orders/{$orderA->reference}";

        $this->asUser($memberA)->post("{$base}/confirm")->assertRedirect();

        $this->asUser($memberA)->post("{$base}/shipments", [
            'lines' => [['order_item_id' => (int) $itemB->id, 'quantity' => 1]],
        ])->assertSessionHasErrors('fulfilment');

        $this->assertSame(0, Shipment::query()->count());
        $this->assertSame(0, (int) $itemB->refresh()->allocated_quantity);
    }

    #[Test]
    public function only_the_roles_that_run_a_warehouse_may_move_a_parcel(): void
    {
        ['offer' => $offer, 'seller' => $seller] = $this->sellableOffer(priceMinor: 4_000, stock: 10);

        $order = $this->placeOrder([[$offer, 1]]);
        $this->payFor($order);

        $sellerOrder = $this->sellerOrderFor($order->id);
        $base = "/seller/orders/{$sellerOrder->reference}";

        // Read-only and finance roles can look and cannot touch.
        foreach ([SellerRole::Viewer, SellerRole::FinanceManager, SellerRole::CatalogManager] as $role) {
            ['user' => $user] = $this->member($seller->id, $role);

            if ($role === SellerRole::CatalogManager) {
                // Cannot even open it: no orders.view.
                $this->asUser($user)->get($base)->assertForbidden();
            } else {
                $this->asUser($user)->get($base)->assertOk();
            }

            $this->asUser($user)->post("{$base}/confirm")->assertForbidden();
        }

        $this->assertSame(SellerOrderStatus::Paid, $sellerOrder->refresh()->status);

        // A fulfilment manager is exactly the role for this.
        ['user' => $packer] = $this->member($seller->id, SellerRole::FulfillmentManager);

        $this->asUser($packer)->post("{$base}/confirm")->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(SellerOrderStatus::Confirmed, $sellerOrder->refresh()->status);
    }

    #[Test]
    public function a_parcel_cannot_be_sent_without_a_carrier_and_cannot_arrive_before_it_is_sent(): void
    {
        ['offer' => $offer, 'seller' => $seller] = $this->sellableOffer(priceMinor: 4_000, stock: 10);
        ['user' => $member] = $this->member($seller->id);

        $order = $this->placeOrder([[$offer, 1]]);
        $this->payFor($order);

        $sellerOrder = $this->sellerOrderFor($order->id);
        $item = OrderItem::query()->where('seller_order_id', $sellerOrder->id)->firstOrFail();
        $base = "/seller/orders/{$sellerOrder->reference}";

        $this->asUser($member)->post("{$base}/confirm")->assertRedirect();

        // A box may be made up before the carrier is known.
        $this->asUser($member)->post("{$base}/shipments", [
            'lines' => [['order_item_id' => (int) $item->id, 'quantity' => 1]],
        ])->assertSessionHasNoErrors();

        $parcel = Shipment::query()->firstOrFail();

        $this->asUser($member)->post("{$base}/shipments/{$parcel->public_id}/deliver")
            ->assertSessionHasErrors('fulfilment');

        $this->asUser($member)->post("{$base}/shipments/{$parcel->public_id}/ship")
            ->assertSessionHasErrors('fulfilment');

        $this->assertSame(ShipmentStatus::Draft, $parcel->refresh()->status);

        // With tracking, it goes.
        $this->asUser($member)->post("{$base}/shipments/{$parcel->public_id}/tracking", [
            'carrier' => 'Barry’s Vans',
            'tracking_number' => 'BV-0001',
        ])->assertSessionHasNoErrors();

        $this->asUser($member)->post("{$base}/shipments/{$parcel->public_id}/ship")
            ->assertSessionHasNoErrors();

        $this->assertSame(ShipmentStatus::Shipped, $parcel->refresh()->status);

        // An unknown carrier is accepted, and gets no link.
        $this->assertSame('Barry’s Vans', $parcel->carrier_name);
        $this->assertNull($parcel->tracking_url);
    }

    #[Test]
    public function a_seller_reports_a_problem_rather_than_refunding_it_themselves(): void
    {
        ['offer' => $offer, 'seller' => $seller] = $this->sellableOffer(priceMinor: 4_000, stock: 10);
        ['user' => $member] = $this->member($seller->id);

        $order = $this->placeOrder([[$offer, 1]]);
        $this->payFor($order);

        $sellerOrder = $this->sellerOrderFor($order->id);
        $base = "/seller/orders/{$sellerOrder->reference}";

        $this->asUser($member)->post("{$base}/issues", [
            'reason' => 'out_of_stock_after_sale',
            'note' => 'The last one was damaged in the stockroom; we cannot send it.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->asUser($member)->get($base)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('fulfilment.issues.0.reason', 'out_of_stock_after_sale')
                ->where('fulfilment.issues.0.resolvedAt', null));

        // §26: no refund route exists for a seller, at any URL.
        $this->asUser($member)
            ->post("/admin/payments/{$order->reference}/refunds", [
                'reason' => 'Cannot fulfil, refunding myself.',
                'lines' => [['order_item_id' => 1, 'amount_minor' => 4_000]],
            ])
            ->assertRedirect('/admin/login');

        $this->assertSame(0, Refund::query()->count());
    }
}
