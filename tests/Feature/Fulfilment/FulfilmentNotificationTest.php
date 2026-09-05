<?php

declare(strict_types=1);

namespace Tests\Feature\Fulfilment;

use App\Modules\Commission\Models\CommissionRule;
use App\Modules\Events\Enums\InteractionEventType;
use App\Modules\Events\Models\InteractionEvent;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Actions\MarkShipmentDelivered;
use App\Modules\Orders\Actions\MarkShipmentShipped;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Notifications\ShipmentDeliveredNotification;
use App\Modules\Orders\Notifications\ShipmentShippedNotification;
use App\Support\Queues;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsCommerceFixtures;
use Tests\Feature\Orders\BuildsPlacedOrders;
use Tests\Feature\Payments\BuildsPayableOrders;
use Tests\TestCase;

/**
 * What the customer is told, and how many times.
 *
 * Per parcel, because that is what happened — and exactly once, which is a
 * guarantee the actions provide rather than the listener: a parcel that
 * has already moved is refused under a row lock, so a retried job sends
 * nothing.
 */
final class FulfilmentNotificationTest extends TestCase
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
        Notification::fake();
    }

    #[Test]
    public function a_dispatch_is_announced_once_with_its_tracking(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 10);
        $user = User::factory()->create();
        $order = $this->placeOrder([[$offer, 2]], userId: $user->id);

        $this->payFor($order);

        $sellerOrder = $this->sellerOrderFor($order->id);
        $shipment = $this->shipEverything($sellerOrder);

        Notification::assertSentToTimes($user, ShipmentShippedNotification::class, 1);

        Notification::assertSentTo(
            $user,
            ShipmentShippedNotification::class,
            function (ShipmentShippedNotification $notification) use ($order): bool {
                $this->assertSame($order->reference, $notification->orderReference);
                $this->assertSame('USPS', $notification->carrierName);
                $this->assertSame('9400100000012345678901', $notification->trackingNumber);
                $this->assertNotNull($notification->trackingUrl);
                $this->assertSame(2, $notification->items[0]['quantity'] ?? 0);
                // §65: never inline in the request that recorded it.
                $this->assertSame(Queues::EMAILS, $notification->queue);

                return true;
            },
        );

        // Pressing "sent" again sends nothing.
        app(MarkShipmentShipped::class)($shipment->refresh());
        app(MarkShipmentShipped::class)($shipment->refresh());

        Notification::assertSentToTimes($user, ShipmentShippedNotification::class, 1);
    }

    #[Test]
    public function an_arrival_is_announced_once_and_says_who_recorded_it(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 10);
        $user = User::factory()->create();
        $order = $this->placeOrder([[$offer, 1]], userId: $user->id);

        $this->payFor($order);

        $shipment = $this->shipEverything($this->sellerOrderFor($order->id));

        app(MarkShipmentDelivered::class)($shipment);
        app(MarkShipmentDelivered::class)($shipment->refresh());
        app(MarkShipmentDelivered::class)($shipment->refresh());

        Notification::assertSentToTimes($user, ShipmentDeliveredNotification::class, 1);

        Notification::assertSentTo(
            $user,
            ShipmentDeliveredNotification::class,
            function (ShipmentDeliveredNotification $notification): bool {
                $this->assertTrue($notification->completesTheOrder);

                // §56: recorded by a person, and the message says so.
                $mail = $notification->toMail(new AnonymousNotifiable);
                $body = implode(' ', $mail->introLines);

                $this->assertStringContainsString('recorded by the seller', $body);
                $this->assertStringNotContainsString('carrier verified', strtolower($body));

                return true;
            },
        );
    }

    #[Test]
    public function a_delivery_that_does_not_finish_the_order_says_so(): void
    {
        ['offer' => $a, 'seller' => $sellerA] = $this->sellableOffer(title: 'Kettle', priceMinor: 4_000, stock: 5);
        ['offer' => $b, 'seller' => $sellerB] = $this->sellableOffer(title: 'Grinder', priceMinor: 9_000, stock: 5);

        $user = User::factory()->create();
        $order = $this->placeOrder([[$a, 1], [$b, 1]], userId: $user->id);

        $this->payFor($order);

        $this->deliver($this->shipEverything($this->sellerOrderFor($order->id, $sellerA->id)));

        Notification::assertSentTo(
            $user,
            ShipmentDeliveredNotification::class,
            function (ShipmentDeliveredNotification $notification): bool {
                $this->assertFalse(
                    $notification->completesTheOrder,
                    'One seller of two delivering is not the order arriving.',
                );

                return true;
            },
        );

        // The second seller's delivery is the one that finishes it.
        $this->deliver($this->shipEverything($this->sellerOrderFor($order->id, $sellerB->id)));

        Notification::assertSentToTimes($user, ShipmentDeliveredNotification::class, 2);
    }

    #[Test]
    public function a_guest_is_told_at_the_address_they_gave(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 10);
        $order = $this->placeOrder([[$offer, 1]], email: 'guest@example.test');

        $this->payFor($order);

        $this->deliver($this->shipEverything($this->sellerOrderFor($order->id)));

        Notification::assertSentTo(
            new AnonymousNotifiable,
            ShipmentShippedNotification::class,
            static fn (
                ShipmentShippedNotification $n,
                array $channels,
                AnonymousNotifiable $notifiable,
            ): bool => $notifiable->routes['mail'] === 'guest@example.test',
        );
    }

    #[Test]
    public function two_parcels_produce_two_announcements(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 10);
        $user = User::factory()->create();
        $order = $this->placeOrder([[$offer, 3]], userId: $user->id);

        $this->payFor($order);

        $sellerOrder = $this->sellerOrderFor($order->id);
        $item = OrderItem::query()->where('seller_order_id', $sellerOrder->id)->firstOrFail();

        $first = $this->shipmentFor($sellerOrder, [['order_item_id' => (int) $item->id, 'quantity' => 2]]);
        app(MarkShipmentShipped::class)($first);

        $second = $this->shipmentFor($sellerOrder, [['order_item_id' => (int) $item->id, 'quantity' => 1]]);
        app(MarkShipmentShipped::class)($second);

        // Two boxes, two messages: one covering both would have been wrong
        // about the first while the second was still being packed.
        Notification::assertSentToTimes($user, ShipmentShippedNotification::class, 2);
    }

    #[Test]
    public function fulfilment_is_recorded_in_the_operational_stream(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 10);
        $order = $this->placeOrder([[$offer, 1]]);

        $this->payFor($order);

        $sellerOrder = $this->sellerOrderFor($order->id);
        $this->deliver($this->shipEverything($sellerOrder));

        $recorded = InteractionEvent::query()->pluck('event_type')->all();

        foreach ([
            InteractionEventType::OrderConfirmed,
            InteractionEventType::ShipmentCreated,
            InteractionEventType::ShipmentShipped,
            InteractionEventType::ShipmentDelivered,
            InteractionEventType::OrderDelivered,
        ] as $type) {
            $this->assertContains($type, $recorded, "{$type->value} was not recorded.");
        }

        // §55: operational, never behavioural — these must not influence
        // what the marketplace recommends.
        foreach ([
            InteractionEventType::ShipmentShipped,
            InteractionEventType::OrderDelivered,
        ] as $type) {
            $this->assertSame(0, $type->affinityWeight());
        }
    }
}
