<?php

declare(strict_types=1);

namespace Tests\Feature\Fulfilment;

use App\Modules\Commission\Models\CommissionRule;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Actions\MarkShipmentShipped;
use App\Modules\Orders\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsCommerceFixtures;
use Tests\Feature\Orders\BuildsPlacedOrders;
use Tests\Feature\Payments\BuildsPayableOrders;
use Tests\TestCase;

/**
 * What the customer is told about where their order is.
 *
 * The risk this guards is a comfortable lie: a marketplace order is
 * several deliveries, and one summary status that says "shipped" when a
 * third of it has left is the kind of wrong a customer only discovers at
 * the door.
 */
final class CustomerTrackingTest extends TestCase
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

    /** The derived parent state, read from the page's own props. */
    private function summaryState(TestResponse $response): string
    {
        /** @var array<string, mixed> $page */
        $page = $response->viewData('page');

        /** @var array{fulfilment: array{summary: array{state: string}}} $props */
        $props = $page['props'];

        return $props['fulfilment']['summary']['state'];
    }

    #[Test]
    public function each_seller_appears_as_its_own_delivery(): void
    {
        ['offer' => $a, 'seller' => $sellerA] = $this->sellableOffer(title: 'Kettle', priceMinor: 4_000, stock: 5);
        ['offer' => $b, 'seller' => $sellerB] = $this->sellableOffer(title: 'Grinder', priceMinor: 9_000, stock: 5);
        ['offer' => $c, 'seller' => $sellerC] = $this->sellableOffer(title: 'Scale', priceMinor: 2_000, stock: 5);

        $user = User::factory()->create();
        $order = $this->placeOrder([[$a, 1], [$b, 1], [$c, 1]], userId: $user->id);

        $this->payFor($order);

        // A delivered, B shipped, C untouched.
        $orderA = $this->sellerOrderFor($order->id, $sellerA->id);
        $orderB = $this->sellerOrderFor($order->id, $sellerB->id);

        $this->deliver($this->shipEverything($orderA));
        $this->shipEverything($orderB);

        $response = $this->asUser($user)->get("/account/orders/{$order->reference}")->assertOk();

        $response->assertInertia(fn ($page) => $page
            // The parent is only as far along as its least advanced seller.
            ->where('fulfilment.summary.state', 'partially_delivered')
            ->where('fulfilment.summary.sellerCount', 3)
            ->where('fulfilment.summary.deliveredCount', 1)
            ->has('fulfilment.groups', 3)
            ->where('fulfilment.groups.0.status', 'delivered')
            ->where('fulfilment.groups.1.status', 'shipped')
            ->where('fulfilment.groups.2.status', 'paid')
            ->where('fulfilment.groups.1.shipments.0.carrierName', 'USPS')
            ->has('fulfilment.groups.2.shipments', 0));

        // Never the flattened lie.
        $this->assertNotSame('delivered', $this->summaryState($response));

        // Every seller's own reference is present, and its tracking with it.
        foreach ([$sellerA, $sellerB, $sellerC] as $seller) {
            $sellerOrder = $this->sellerOrderFor($order->id, $seller->id);

            $this->assertStringContainsString($sellerOrder->reference, (string) $response->getContent());
        }
    }

    #[Test]
    public function the_parent_is_delivered_only_when_every_seller_is(): void
    {
        ['offer' => $a, 'seller' => $sellerA] = $this->sellableOffer(title: 'Kettle', priceMinor: 4_000, stock: 5);
        ['offer' => $b, 'seller' => $sellerB] = $this->sellableOffer(title: 'Grinder', priceMinor: 9_000, stock: 5);

        $user = User::factory()->create();
        $order = $this->placeOrder([[$a, 1], [$b, 1]], userId: $user->id);

        $this->payFor($order);

        $summary = fn (): string => $this->summaryState(
            $this->asUser($user)->get("/account/orders/{$order->reference}")->assertOk(),
        );

        $this->assertSame('in_progress', $summary());

        $this->shipEverything($this->sellerOrderFor($order->id, $sellerA->id));
        $this->assertSame('partially_shipped', $summary());

        $parcelB = $this->shipEverything($this->sellerOrderFor($order->id, $sellerB->id));
        $this->assertSame('shipped', $summary());

        $this->deliver($this->sellerOrderFor($order->id, $sellerA->id)->shipments()->firstOrFail());
        $this->assertSame('partially_delivered', $summary());

        $this->deliver($parcelB);
        $this->assertSame('delivered', $summary());
    }

    #[Test]
    public function a_partly_shipped_seller_shows_only_what_actually_left(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 10);
        $user = User::factory()->create();
        $order = $this->placeOrder([[$offer, 3]], userId: $user->id);

        $this->payFor($order);

        $sellerOrder = $this->sellerOrderFor($order->id);
        $item = OrderItem::query()->where('seller_order_id', $sellerOrder->id)->firstOrFail();

        $shipment = $this->shipmentFor($sellerOrder, [['order_item_id' => (int) $item->id, 'quantity' => 1]]);
        app(MarkShipmentShipped::class)($shipment);

        $this->asUser($user)->get("/account/orders/{$order->reference}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('fulfilment.groups.0.status', 'partially_shipped')
                ->has('fulfilment.groups.0.shipments', 1)
                // One of three, said plainly.
                ->where('fulfilment.groups.0.shipments.0.items.0.quantity', 1));
    }

    #[Test]
    public function tracking_is_never_reachable_without_signing_in(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 5);

        $mine = User::factory()->create();
        $theirs = User::factory()->create();

        $order = $this->placeOrder([[$offer, 1]], userId: $theirs->id);
        $this->payFor($order);
        $this->shipEverything($this->sellerOrderFor($order->id));

        /*
         * §44: an order number is printed on emails and packing slips. It
         * is not a secret, so it cannot be a credential — there is no
         * tracking page that takes one and nothing else.
         */
        $this->get("/account/orders/{$order->reference}")->assertRedirect('/login');
        $this->asUser($mine)->get("/account/orders/{$order->reference}")->assertNotFound();

        // And there is no unauthenticated tracking route to find.
        $this->get("/track/{$order->reference}")->assertNotFound();
        $this->get("/orders/{$order->reference}")->assertNotFound();
        $this->get("/tracking/{$order->reference}")->assertNotFound();

        $this->asUser($theirs)->get("/account/orders/{$order->reference}")
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    #[Test]
    public function a_customer_cannot_move_a_parcel(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 4_000, stock: 5);

        $user = User::factory()->create();
        $order = $this->placeOrder([[$offer, 1]], userId: $user->id);

        $this->payFor($order);

        $sellerOrder = $this->sellerOrderFor($order->id);
        $shipment = $this->shipEverything($sellerOrder);

        /*
         * §20's delivery authority: visiting a tracking page is not
         * delivery, and a customer must not be able to start a seller's
         * earnings clock by posting to a URL.
         */
        $base = "/seller/orders/{$sellerOrder->reference}";

        /*
         * 404 rather than 403, and deliberately: a customer is not a
         * member of any seller, so the seller order does not exist for
         * them at all. Telling them it exists but is not theirs would
         * confirm the reference is real.
         */
        $this->asUser($user)->post("{$base}/shipments/{$shipment->public_id}/deliver")->assertNotFound();
        $this->asUser($user)->post("{$base}/confirm")->assertNotFound();

        $this->assertNull($sellerOrder->refresh()->earnings_clear_at);
        $this->assertNull($shipment->refresh()->delivered_at);
    }
}
