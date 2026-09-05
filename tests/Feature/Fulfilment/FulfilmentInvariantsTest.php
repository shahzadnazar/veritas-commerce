<?php

declare(strict_types=1);

namespace Tests\Feature\Fulfilment;

use App\Modules\Commission\Models\CommissionRule;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Ledger\Enums\LedgerEntryStatus;
use App\Modules\Ledger\Models\SellerLedgerEntry;
use App\Modules\Orders\Actions\CreateShipment;
use App\Modules\Orders\Actions\MarkShipmentDelivered;
use App\Modules\Orders\Actions\MarkShipmentShipped;
use App\Modules\Orders\Enums\MarketplaceOrderStatus;
use App\Modules\Orders\Enums\SellerOrderStatus;
use App\Modules\Orders\Enums\ShipmentStatus;
use App\Modules\Orders\Exceptions\FulfilmentRefused;
use App\Modules\Orders\Models\Shipment;
use App\Modules\Orders\Queries\FulfilmentQuantities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsCommerceFixtures;
use Tests\Feature\Orders\BuildsPlacedOrders;
use Tests\Feature\Payments\BuildsPayableOrders;
use Tests\TestCase;

/**
 * The six things M6 had to prove before a fulfilment screen existed.
 *
 * They are the ones where a mistake is expensive and invisible: goods
 * shipped for an order nobody paid for, the same unit sent twice, stock
 * debited a second time, a parent order marked delivered because one of
 * three sellers arrived, a seller paid before their goods landed, and a
 * clearing job that pays twice when it runs twice.
 *
 * Written against the domain actions rather than HTTP, because these must
 * hold whatever calls them — a controller, a queued job, an admin
 * override, or a console command.
 */
final class FulfilmentInvariantsTest extends TestCase
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
    public function an_unpaid_order_cannot_ship(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 10);
        $order = $this->placeOrder([[$offer, 2]]);

        $sellerOrder = $this->sellerOrderFor($order->id);
        $item = $this->itemsOf($sellerOrder)[0];

        $this->assertSame(SellerOrderStatus::PendingPayment, $sellerOrder->status);
        $this->assertFalse($sellerOrder->status->isActionable());

        try {
            app(CreateShipment::class)($sellerOrder, [
                ['order_item_id' => (int) $item->id, 'quantity' => 1],
            ]);

            $this->fail('A seller must not be able to pack an order nobody has paid for.');
        } catch (FulfilmentRefused $refused) {
            $this->assertSame('not_paid', $refused->reason);
        }

        $this->assertSame(0, Shipment::query()->count());
        $this->assertSame(0, (int) $item->refresh()->allocated_quantity);
    }

    #[Test]
    public function two_shipments_racing_for_the_last_unit_cannot_both_have_it(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 10);
        $order = $this->placeOrder([[$offer, 3]]);

        $this->payFor($order);

        $sellerOrder = $this->sellerOrderFor($order->id);
        $item = $this->itemsOf($sellerOrder)[0];

        // Two of three are already in a parcel; one remains.
        $this->shipmentFor($sellerOrder, [['order_item_id' => (int) $item->id, 'quantity' => 2]]);

        $accepted = 0;

        foreach (['first', 'second'] as $_) {
            try {
                $this->shipmentFor($sellerOrder, [['order_item_id' => (int) $item->id, 'quantity' => 1]]);
                $accepted++;
            } catch (FulfilmentRefused $refused) {
                $this->assertSame('exceeds_remaining', $refused->reason);
            }
        }

        $this->assertSame(1, $accepted, 'One unit is one parcel.');
        $this->assertSame(3, (int) $item->refresh()->allocated_quantity);

        // And the database itself refuses the over-allocation, whatever a
        // future caller believes about the arithmetic.
        $this->expectExceptionMessageMatches('/order_items_allocated_within_ordered/');

        DB::table('order_items')->where('id', $item->id)->update(['allocated_quantity' => 4]);
    }

    #[Test]
    public function shipping_does_not_take_the_stock_off_the_shelf_a_second_time(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 10);
        $order = $this->placeOrder([[$offer, 3]]);

        $this->payFor($order);

        // The sale happened at payment: on_hand fell by three, once.
        $onHand = fn (): int => (int) DB::table('inventory_balances')
            ->where('offer_id', $offer->id)
            ->value('on_hand');
        $reserved = fn (): int => (int) DB::table('inventory_balances')
            ->where('offer_id', $offer->id)
            ->value('reserved');

        $movementsAfterPayment = InventoryMovement::query()->count();

        $this->assertSame(7, $onHand());

        $sellerOrder = $this->sellerOrderFor($order->id);
        $shipment = $this->shipEverything($sellerOrder);
        $this->deliver($shipment);

        $this->assertSame(7, $onHand(), 'Shipping is not a second sale.');
        $this->assertSame(0, $reserved());
        $this->assertSame(
            $movementsAfterPayment,
            InventoryMovement::query()->count(),
            'Fulfilment writes no inventory movements at all.',
        );
    }

    #[Test]
    public function one_seller_delivering_does_not_deliver_the_whole_marketplace_order(): void
    {
        ['offer' => $a, 'seller' => $sellerA] = $this->sellableOffer(title: 'Kettle', priceMinor: 4_000, stock: 5);
        ['offer' => $b, 'seller' => $sellerB] = $this->sellableOffer(title: 'Grinder', priceMinor: 9_000, stock: 5);

        $order = $this->placeOrder([[$a, 1], [$b, 1]]);
        $this->payFor($order);

        $orderA = $this->sellerOrderFor($order->id, $sellerA->id);
        $orderB = $this->sellerOrderFor($order->id, $sellerB->id);

        $this->deliver($this->shipEverything($orderA));

        $this->assertSame(SellerOrderStatus::Delivered, $orderA->refresh()->status);
        $this->assertSame(SellerOrderStatus::Paid, $orderB->refresh()->status, 'B has not been touched.');

        // The parent is not delivered, and neither is it completed.
        $this->assertSame(MarketplaceOrderStatus::Paid, $order->refresh()->status);
        $this->assertNull($order->completed_at);

        // Nor does A's delivery start B's clearing clock.
        $this->assertNotNull($orderA->refresh()->earnings_clear_at);
        $this->assertNull($orderB->refresh()->earnings_clear_at);
    }

    #[Test]
    public function a_partly_delivered_seller_order_is_not_delivered(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 10);
        $order = $this->placeOrder([[$offer, 3]]);

        $this->payFor($order);

        $sellerOrder = $this->sellerOrderFor($order->id);
        $item = $this->itemsOf($sellerOrder)[0];

        $first = $this->shipmentFor($sellerOrder, [['order_item_id' => (int) $item->id, 'quantity' => 2]]);
        app(MarkShipmentShipped::class)($first);

        $this->assertSame(SellerOrderStatus::PartiallyShipped, $sellerOrder->refresh()->status);

        $this->deliver($first);

        $this->assertSame(SellerOrderStatus::PartiallyDelivered, $sellerOrder->refresh()->status);
        $this->assertNull($sellerOrder->earnings_clear_at, 'One box arriving starts no clock.');

        // The last unit goes out and arrives.
        $second = $this->shipmentFor($sellerOrder, [['order_item_id' => (int) $item->id, 'quantity' => 1]]);
        app(MarkShipmentShipped::class)($second);

        /*
         * Still partially delivered, not "shipped": everything has left,
         * but two of the three units have already arrived, and the more
         * specific fact is the one worth telling the customer.
         */
        $this->assertSame(SellerOrderStatus::PartiallyDelivered, $sellerOrder->refresh()->status);

        $this->deliver($second);

        $this->assertSame(SellerOrderStatus::Delivered, $sellerOrder->refresh()->status);
        $this->assertNotNull($sellerOrder->earnings_clear_at);
    }

    #[Test]
    public function delivery_starts_the_clock_and_makes_nothing_spendable(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 10_000, stock: 5);
        $order = $this->placeOrder([[$offer, 1]]);

        $this->payFor($order);

        $earning = SellerLedgerEntry::query()->withoutGlobalScopes()->firstOrFail();

        // Payment recorded the money and made none of it available.
        $this->assertSame(LedgerEntryStatus::Pending, $earning->status);
        $this->assertNull($earning->available_at);

        $sellerOrder = $this->sellerOrderFor($order->id);
        $this->deliver($this->shipEverything($sellerOrder));

        $sellerOrder->refresh();

        // Seven days, from the platform default, computed from delivery.
        $this->assertNotNull($sellerOrder->earnings_clear_at);
        $this->assertNotNull($sellerOrder->delivered_at);
        $this->assertSame(
            7,
            (int) $sellerOrder->delivered_at->diffInDays($sellerOrder->earnings_clear_at),
        );

        // Delivery does not itself make money spendable — that is the
        // clearing sweep's job, and only once the date has passed.
        $this->assertNotSame(
            LedgerEntryStatus::Available,
            $earning->refresh()->status,
            'Arriving is not the same as clearing.',
        );
    }

    #[Test]
    public function delivering_the_same_parcel_twice_records_one_arrival(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 10);
        $order = $this->placeOrder([[$offer, 2]]);

        $this->payFor($order);

        $sellerOrder = $this->sellerOrderFor($order->id);
        $shipment = $this->shipEverything($sellerOrder);

        $this->assertTrue(app(MarkShipmentDelivered::class)($shipment));
        $this->assertFalse(app(MarkShipmentDelivered::class)($shipment), 'A second click is not a second arrival.');
        $this->assertFalse(app(MarkShipmentDelivered::class)($shipment->refresh()));

        $item = $this->itemsOf($sellerOrder)[0];

        // Delivered units counted once, one clearing date, one history row.
        $this->assertSame(2, (int) $item->refresh()->delivered_quantity);
        $this->assertSame(1, $shipment->refresh()->history()->where('to_status', ShipmentStatus::Delivered->value)->count());

        $clearAt = $sellerOrder->refresh()->earnings_clear_at;

        $this->assertNotNull($clearAt);

        app(MarkShipmentDelivered::class)($shipment->refresh());

        $this->assertEquals($clearAt, $sellerOrder->refresh()->earnings_clear_at, 'The clock is not restarted.');
    }

    #[Test]
    public function the_fulfilment_arithmetic_is_computed_in_one_place(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 10);
        $order = $this->placeOrder([[$offer, 4]]);

        $this->payFor($order);

        $sellerOrder = $this->sellerOrderFor($order->id);
        $item = $this->itemsOf($sellerOrder)[0];

        $state = fn () => app(FulfilmentQuantities::class)->forSellerOrder($sellerOrder->refresh())[(int) $item->id];

        $this->assertSame(4, $state()->ordered);
        $this->assertSame(4, $state()->remainingToShip());

        $shipment = $this->shipmentFor($sellerOrder, [['order_item_id' => (int) $item->id, 'quantity' => 3]]);

        // Allocated to a draft parcel: no longer available to a second one,
        // and not yet shipped.
        $this->assertSame(3, $state()->allocated);
        $this->assertSame(0, $state()->shipped);
        $this->assertSame(1, $state()->remainingToShip());

        app(MarkShipmentShipped::class)($shipment);

        $this->assertSame(3, $state()->shipped);
        $this->assertSame(1, $state()->remainingToShip());

        $this->deliver($shipment);

        $this->assertSame(3, $state()->delivered);
        $this->assertFalse($state()->isFullyDelivered());
    }
}
