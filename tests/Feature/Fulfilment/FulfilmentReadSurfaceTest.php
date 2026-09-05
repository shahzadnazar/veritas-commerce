<?php

declare(strict_types=1);

namespace Tests\Feature\Fulfilment;

use App\Modules\Commission\Models\CommissionRule;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Identity\Models\User;
use App\Modules\Offers\Models\Offer;
use App\Modules\Orders\Actions\AcknowledgeSellerOrder;
use App\Modules\Orders\Actions\CompleteDeliveredSellerOrders;
use App\Modules\Orders\Actions\MarkShipmentShipped;
use App\Modules\Orders\Enums\MarketplaceOrderStatus;
use App\Modules\Orders\Enums\SellerOrderStatus;
use App\Modules\Orders\Events\SellerOrderDelivered;
use App\Modules\Orders\Events\ShipmentDelivered;
use App\Modules\Orders\Events\ShipmentShipped;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderStatusHistory;
use App\Modules\Sellers\Enums\SellerRole;
use App\Modules\Sellers\Models\SellerMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsCommerceFixtures;
use Tests\Feature\Orders\BuildsPlacedOrders;
use Tests\Feature\Payments\BuildsPayableOrders;
use Tests\TestCase;

/**
 * The read surfaces, the history they write, and the events they emit.
 *
 * Query counts are asserted against a growing dataset rather than a fixed
 * number: what matters is not that a page runs eleven queries but that it
 * runs the same eleven when the order has ten parcels instead of one. A
 * fixed number would fail on every legitimate refactor and pass on the
 * N+1 it was meant to catch.
 */
final class FulfilmentReadSurfaceTest extends TestCase
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

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = count(DB::getRawQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    #[Test]
    public function repeating_a_transition_does_not_repeat_its_history(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 10);
        $order = $this->placeOrder([[$offer, 1]]);

        $this->payFor($order);

        $sellerOrder = $this->sellerOrderFor($order->id);

        $this->assertTrue(app(AcknowledgeSellerOrder::class)->confirm($sellerOrder));
        $this->assertFalse(app(AcknowledgeSellerOrder::class)->confirm($sellerOrder->refresh()));
        $this->assertFalse(app(AcknowledgeSellerOrder::class)->confirm($sellerOrder->refresh()));

        $this->assertSame(
            1,
            OrderStatusHistory::query()
                ->where('seller_order_id', $sellerOrder->id)
                ->where('to_status', SellerOrderStatus::Confirmed->value)
                ->count(),
            'A double-clicked button is one confirmation.',
        );
    }

    #[Test]
    public function a_multi_seller_parent_completes_only_when_every_seller_has(): void
    {
        ['offer' => $a, 'seller' => $sellerA] = $this->sellableOffer(title: 'Kettle', priceMinor: 4_000, stock: 5);
        ['offer' => $b, 'seller' => $sellerB] = $this->sellableOffer(title: 'Grinder', priceMinor: 9_000, stock: 5);

        $order = $this->placeOrder([[$a, 1], [$b, 1]]);
        $this->payFor($order);

        $orderA = $this->sellerOrderFor($order->id, $sellerA->id);
        $orderB = $this->sellerOrderFor($order->id, $sellerB->id);

        $this->deliver($this->shipEverything($orderA));

        $this->travel(8)->days();
        app(CompleteDeliveredSellerOrders::class)();

        // A is finished; B has not even been delivered.
        $this->assertSame(SellerOrderStatus::Completed, $orderA->refresh()->status);
        $this->assertSame(SellerOrderStatus::Paid, $orderB->refresh()->status);
        $this->assertNull($order->refresh()->completed_at);
        $this->assertNotSame(MarketplaceOrderStatus::Completed, $order->status);

        // B catches up.
        $this->deliver($this->shipEverything($orderB));
        $this->travel(8)->days();
        app(CompleteDeliveredSellerOrders::class)();

        $this->assertSame(SellerOrderStatus::Completed, $orderB->refresh()->status);
    }

    #[Test]
    public function each_fulfilment_event_fires_exactly_once(): void
    {
        Event::fake([ShipmentShipped::class, ShipmentDelivered::class, SellerOrderDelivered::class]);

        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 10);
        $order = $this->placeOrder([[$offer, 2]]);

        $this->payFor($order);

        $sellerOrder = $this->sellerOrderFor($order->id);
        $shipment = $this->shipEverything($sellerOrder);

        // Every one of these repeats is a retried job or a second click.
        app(MarkShipmentShipped::class)($shipment->refresh());
        app(MarkShipmentShipped::class)($shipment->refresh());

        $this->deliver($shipment->refresh());
        $this->deliver($shipment->refresh());
        $this->deliver($shipment->refresh());

        Event::assertDispatchedTimes(ShipmentShipped::class, 1);
        Event::assertDispatchedTimes(ShipmentDelivered::class, 1);
        Event::assertDispatchedTimes(SellerOrderDelivered::class, 1);
    }

    #[Test]
    public function the_seller_order_list_does_not_grow_a_query_per_order(): void
    {
        ['offer' => $offer, 'seller' => $seller] = $this->sellableOffer(priceMinor: 4_000, stock: 60);
        ['user' => $member] = $this->member($seller->id);

        $order = $this->placeOrder([[$offer, 1]]);
        $this->payFor($order);

        $one = $this->countQueries(fn () => $this->asUser($member)->get('/seller/orders')->assertOk());

        foreach (range(1, 8) as $_) {
            $extra = $this->placeOrder([[$offer, 1]]);
            $this->payFor($extra);
            $this->shipEverything($this->sellerOrderFor($extra->id));
        }

        $this->assertSame(
            $one,
            $this->countQueries(fn () => $this->asUser($member)->get('/seller/orders')->assertOk()),
        );
    }

    #[Test]
    public function the_seller_fulfilment_detail_does_not_grow_a_query_per_parcel(): void
    {
        ['offer' => $offer, 'seller' => $seller] = $this->sellableOffer(priceMinor: 4_000, stock: 60);
        ['user' => $member] = $this->member($seller->id);

        $order = $this->placeOrder([[$offer, 6]]);
        $this->payFor($order);

        $sellerOrder = $this->sellerOrderFor($order->id);
        $item = OrderItem::query()->where('seller_order_id', $sellerOrder->id)->firstOrFail();
        $url = "/seller/orders/{$sellerOrder->reference}";

        $first = $this->shipmentFor($sellerOrder, [['order_item_id' => (int) $item->id, 'quantity' => 1]]);
        app(MarkShipmentShipped::class)($first);

        $one = $this->countQueries(fn () => $this->asUser($member)->get($url)->assertOk());

        // Five more parcels, each with its own items and history.
        foreach (range(1, 5) as $_) {
            $parcel = $this->shipmentFor($sellerOrder, [['order_item_id' => (int) $item->id, 'quantity' => 1]]);
            app(MarkShipmentShipped::class)($parcel);
        }

        $this->assertSame(
            $one,
            $this->countQueries(fn () => $this->asUser($member)->get($url)->assertOk()),
            'Shipments, their items and their history are loaded in bounded queries.',
        );
    }

    #[Test]
    public function customer_tracking_does_not_grow_a_query_per_seller(): void
    {
        $user = User::factory()->create();

        ['offer' => $first] = $this->sellableOffer(title: 'Kettle', priceMinor: 4_000, stock: 5);

        $order = $this->placeOrder([[$first, 1]], userId: $user->id);
        $this->payFor($order);
        $this->shipEverything($this->sellerOrderFor($order->id));

        $url = "/account/orders/{$order->reference}";
        $one = $this->countQueries(fn () => $this->asUser($user)->get($url)->assertOk());

        // A second order from four sellers, each with a parcel.
        $offers = [];

        foreach (range(1, 4) as $index) {
            ['offer' => $offer] = $this->sellableOffer(title: "Item {$index}", priceMinor: 2_000, stock: 5);
            $offers[] = [$offer, 1];
        }

        $bigger = $this->placeOrder($offers, userId: $user->id);
        $this->payFor($bigger);

        foreach (Offer::query()->whereIn('id', array_map(
            static fn (array $line): int => (int) $line[0]->id,
            $offers,
        ))->pluck('seller_account_id') as $sellerAccountId) {
            $this->shipEverything($this->sellerOrderFor($bigger->id, (int) $sellerAccountId));
        }

        $this->assertSame(
            $one,
            $this->countQueries(
                fn () => $this->asUser($user)->get("/account/orders/{$bigger->reference}")->assertOk(),
            ),
            'Four sellers must cost the same as one.',
        );
    }

    #[Test]
    public function the_admin_fulfilment_screens_do_not_grow_a_query_per_row(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 60);
        $admin = $this->makeAdmin(AdminRole::MarketplaceAdmin);

        $order = $this->placeOrder([[$offer, 4]]);
        $this->payFor($order);

        $listOnce = $this->countQueries(
            fn () => $this->asAdmin($admin)->get('/admin/fulfilment')->assertOk(),
        );

        $sellerOrder = $this->sellerOrderFor($order->id);
        $item = OrderItem::query()->where('seller_order_id', $sellerOrder->id)->firstOrFail();
        $detail = "/admin/fulfilment/{$sellerOrder->reference}";

        /*
         * Measured with one parcel already made up, not with none: an
         * order with no shipments skips the shipment queries entirely, so
         * a baseline taken there would be comparing a shorter page against
         * a longer one rather than one parcel against four.
         */
        $this->shipmentFor($sellerOrder, [['order_item_id' => (int) $item->id, 'quantity' => 1]]);

        $detailOnce = $this->countQueries(
            fn () => $this->asAdmin($admin)->get($detail)->assertOk(),
        );

        foreach (range(1, 6) as $_) {
            $extra = $this->placeOrder([[$offer, 1]]);
            $this->payFor($extra);
            $this->shipEverything($this->sellerOrderFor($extra->id));
        }

        $this->assertSame(
            $listOnce,
            $this->countQueries(fn () => $this->asAdmin($admin)->get('/admin/fulfilment')->assertOk()),
        );

        // And a detail page with four parcels rather than one.
        foreach (range(1, 3) as $_) {
            $this->shipmentFor($sellerOrder->refresh(), [
                ['order_item_id' => (int) $item->id, 'quantity' => 1],
            ]);
        }

        $this->assertSame(
            $detailOnce,
            $this->countQueries(fn () => $this->asAdmin($admin)->get($detail)->assertOk()),
        );
    }
}
