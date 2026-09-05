<?php

declare(strict_types=1);

namespace Tests\Feature\Fulfilment;

use App\Modules\Commission\Models\CommissionRule;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Orders\Actions\CreateShipment;
use App\Modules\Orders\Actions\MarkShipmentShipped;
use App\Modules\Orders\Actions\UpdateShipmentTracking;
use App\Modules\Orders\Data\ShipmentTracking;
use App\Modules\Orders\Enums\SellerOrderStatus;
use App\Modules\Orders\Exceptions\FulfilmentRefused;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\ShipmentStatusHistory;
use App\Modules\Orders\Queries\FulfilmentQuantities;
use App\Modules\Payments\Actions\RequestRefund;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsCommerceFixtures;
use Tests\Feature\Orders\BuildsPlacedOrders;
use Tests\Feature\Payments\BuildsPayableOrders;
use Tests\TestCase;

/**
 * Where money and goods meet.
 *
 * A refund changes what the seller still owes the customer, and getting
 * that wrong in either direction is expensive: a seller who ships a unit
 * that was refunded has given away stock, and a seller forced to ship
 * something nobody is paying for has been robbed by the platform.
 *
 * The line the marketplace does not cross in M6 is the physical one. Money
 * can come back through the refund domain at any point; goods only come
 * back through a return, and returns are M7's.
 */
final class RefundFulfilmentIntegrationTest extends TestCase
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
    public function a_refund_before_shipment_reduces_what_is_left_to_ship(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 10);
        $order = $this->placeOrder([[$offer, 3]]);

        $this->payFor($order);

        $sellerOrder = $this->sellerOrderFor($order->id);
        $item = OrderItem::query()->where('seller_order_id', $sellerOrder->id)->firstOrFail();

        $state = fn () => app(FulfilmentQuantities::class)->forSellerOrder($sellerOrder->refresh())[(int) $item->id];

        $this->assertSame(3, $state()->remainingToShip());

        // One of three comes back before anything is packed. The line
        // total is 12,000 for three, so one unit is 4,000.
        app(RequestRefund::class)(
            order: $order->refresh(),
            lines: [['order_item_id' => (int) $item->id, 'amount_minor' => 4_000, 'quantity' => 1]],
            reason: 'Customer reduced their order before it shipped.',
        );

        $this->assertSame(1, $state()->refunded);
        $this->assertSame(2, $state()->fulfilable());
        $this->assertSame(2, $state()->remainingToShip(), 'The seller owes two, not three.');
    }

    #[Test]
    public function a_refunded_unit_cannot_be_put_in_a_parcel(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 10);
        $order = $this->placeOrder([[$offer, 2]]);

        $this->payFor($order);

        $sellerOrder = $this->sellerOrderFor($order->id);
        $item = OrderItem::query()->where('seller_order_id', $sellerOrder->id)->firstOrFail();

        app(RequestRefund::class)(
            order: $order->refresh(),
            lines: [['order_item_id' => (int) $item->id, 'amount_minor' => 8_000, 'quantity' => 2]],
            reason: 'The whole line came back before dispatch.',
        );

        $this->confirm($sellerOrder->refresh());

        try {
            app(CreateShipment::class)($sellerOrder->refresh(), [
                ['order_item_id' => (int) $item->id, 'quantity' => 1],
            ]);

            $this->fail('A seller must not ship a unit the customer has had their money back for.');
        } catch (FulfilmentRefused $refused) {
            $this->assertSame('exceeds_remaining', $refused->reason);
        }

        $this->assertSame(0, (int) $item->refresh()->allocated_quantity);
    }

    #[Test]
    public function a_partial_money_refund_does_not_reduce_what_is_owed(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 10);
        $order = $this->placeOrder([[$offer, 2]]);

        $this->payFor($order);

        $sellerOrder = $this->sellerOrderFor($order->id);
        $item = OrderItem::query()->where('seller_order_id', $sellerOrder->id)->firstOrFail();

        // Goodwill for a delay: money back, goods still expected.
        app(RequestRefund::class)(
            order: $order->refresh(),
            lines: [['order_item_id' => (int) $item->id, 'amount_minor' => 1_000, 'quantity' => 0]],
            reason: 'Discount for a late dispatch; the customer still wants the items.',
        );

        $state = app(FulfilmentQuantities::class)->forSellerOrder($sellerOrder->refresh())[(int) $item->id];

        $this->assertSame(0, $state->refunded, 'Money came back; units did not.');
        $this->assertSame(2, $state->remainingToShip());
    }

    #[Test]
    public function a_refund_after_shipment_does_not_put_the_goods_back_on_the_shelf(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 10);
        $order = $this->placeOrder([[$offer, 2]]);

        $this->payFor($order);

        $sellerOrder = $this->sellerOrderFor($order->id);
        $this->deliver($this->shipEverything($sellerOrder));

        $onHand = fn (): int => (int) DB::table('inventory_balances')
            ->where('offer_id', $offer->id)
            ->value('on_hand');
        $movements = InventoryMovement::query()->count();

        $this->assertSame(8, $onHand());

        $item = OrderItem::query()->where('seller_order_id', $sellerOrder->id)->firstOrFail();

        app(RequestRefund::class)(
            order: $order->refresh(),
            lines: [['order_item_id' => (int) $item->id, 'amount_minor' => 8_000, 'quantity' => 2]],
            reason: 'Returned after delivery.',
        );

        /*
         * §25. The money went back; the goods are on a customer's shelf,
         * in a courier's van, or in a bin. Restocking on a financial event
         * would offer stock the seller does not have, and the next buyer
         * would find that out. A physical return is M7's.
         */
        $this->assertSame(8, $onHand());
        $this->assertSame($movements, InventoryMovement::query()->count());
    }

    #[Test]
    public function an_order_whose_remaining_units_are_refunded_still_reaches_delivered(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 10);
        $order = $this->placeOrder([[$offer, 3]]);

        $this->payFor($order);

        $sellerOrder = $this->sellerOrderFor($order->id);
        $item = OrderItem::query()->where('seller_order_id', $sellerOrder->id)->firstOrFail();

        // Two go out and arrive; the third is refunded because the seller
        // has run out.
        $shipment = $this->shipmentFor($sellerOrder, [['order_item_id' => (int) $item->id, 'quantity' => 2]]);
        app(MarkShipmentShipped::class)($shipment);

        app(RequestRefund::class)(
            order: $order->refresh(),
            lines: [['order_item_id' => (int) $item->id, 'amount_minor' => 4_000, 'quantity' => 1]],
            reason: 'Out of stock after the sale; the last unit was refunded.',
        );

        $this->deliver($shipment);

        /*
         * Everything still owed has arrived, so the order is delivered —
         * not held open forever waiting for a unit nobody owes anybody.
         */
        $this->assertSame(SellerOrderStatus::Delivered, $sellerOrder->refresh()->status);
        $this->assertNotNull($sellerOrder->earnings_clear_at);
    }

    #[Test]
    public function tracking_may_be_corrected_before_delivery_and_the_old_value_is_kept(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 10);
        $order = $this->placeOrder([[$offer, 1]]);

        $this->payFor($order);

        $sellerOrder = $this->sellerOrderFor($order->id);
        $shipment = $this->shipEverything($sellerOrder);

        $this->assertSame('USPS', $shipment->carrier_name);

        $corrected = app(UpdateShipmentTracking::class)(
            $shipment,
            ShipmentTracking::of('ups', '1Z999AA10123456784'),
        );

        $this->assertTrue($corrected);
        $this->assertSame('UPS', $shipment->refresh()->carrier_name);
        $this->assertSame('1Z999AA10123456784', $shipment->tracking_number);

        // The number the customer was originally given is still readable.
        $previous = ShipmentStatusHistory::query()
            ->where('shipment_id', $shipment->id)
            ->where('tracking_number', '9400100000012345678901')
            ->get();

        $this->assertGreaterThanOrEqual(1, $previous->count());

        // Setting the same values again is not a second history row.
        $this->assertFalse(app(UpdateShipmentTracking::class)(
            $shipment->refresh(),
            ShipmentTracking::of('ups', '1Z999AA10123456784'),
        ));
    }

    #[Test]
    public function a_delivered_parcels_tracking_is_history_and_needs_a_reason_to_change(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 10);
        $order = $this->placeOrder([[$offer, 1]]);

        $this->payFor($order);

        $sellerOrder = $this->sellerOrderFor($order->id);
        $shipment = $this->shipEverything($sellerOrder);
        $this->deliver($shipment);

        // A seller cannot rewrite the evidence that a parcel arrived.
        try {
            app(UpdateShipmentTracking::class)(
                $shipment->refresh(),
                ShipmentTracking::of('ups', '1Z999AA10123456784'),
            );

            $this->fail('A delivered parcel’s tracking must not be casually rewritten.');
        } catch (FulfilmentRefused $refused) {
            $this->assertSame('tracking_is_history', $refused->reason);
        }

        // An administrator with the correction permission still needs a
        // reason, which goes into the history beside the old value.
        try {
            app(UpdateShipmentTracking::class)(
                $shipment->refresh(),
                ShipmentTracking::of('ups', '1Z999AA10123456784'),
                actorType: 'admin',
                mayCorrectHistory: true,
            );

            $this->fail('A correction to a delivered parcel needs a written reason.');
        } catch (FulfilmentRefused $refused) {
            $this->assertSame('reason_required', $refused->reason);
        }

        $this->assertTrue(app(UpdateShipmentTracking::class)(
            $shipment->refresh(),
            ShipmentTracking::of('ups', '1Z999AA10123456784'),
            actorType: 'admin',
            actorId: 1,
            reason: 'The carrier was recorded wrongly at dispatch; corrected from the manifest.',
            mayCorrectHistory: true,
        ));

        $correction = ShipmentStatusHistory::query()
            ->where('shipment_id', $shipment->id)
            ->where('actor_type', 'admin')
            ->firstOrFail();

        $this->assertStringContainsString('recorded wrongly', (string) $correction->reason);
        $this->assertSame('9400100000012345678901', $correction->tracking_number);
    }

    #[Test]
    public function a_tracking_link_is_generated_and_never_accepted(): void
    {
        $known = ShipmentTracking::of('ups', '1Z999AA10123456784');

        $this->assertSame('UPS', $known->carrierName);
        $this->assertSame('ups', $known->carrierCode);
        $this->assertSame('https://www.ups.com/track?tracknum=1Z999AA10123456784', $known->url());

        // A carrier the platform does not know is still allowed — the
        // world has more couriers than any list — but gets no link.
        $unknown = ShipmentTracking::of('Barry’s Vans', 'BV-0001');

        $this->assertSame('Barry’s Vans', $unknown->carrierName);
        $this->assertNull($unknown->carrierCode);
        $this->assertNull($unknown->url());

        // And a tracking number trying to be a URL of its own is encoded
        // into one path segment rather than escaping the template.
        $hostile = ShipmentTracking::of('ups', 'x&redirect=https://evil.test');

        $this->assertSame(
            'https://www.ups.com/track?tracknum=x%26redirect%3Dhttps%3A%2F%2Fevil.test',
            $hostile->url(),
        );
    }
}
