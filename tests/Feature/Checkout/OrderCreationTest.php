<?php

declare(strict_types=1);

namespace Tests\Feature\Checkout;

use App\Modules\Cart\Actions\AddOfferToCart;
use App\Modules\Cart\Enums\CartStatus;
use App\Modules\Cart\Models\Cart;
use App\Modules\Checkout\Actions\PlaceOrder;
use App\Modules\Checkout\Actions\StartCheckout;
use App\Modules\Checkout\Data\ShippingAddress;
use App\Modules\Checkout\Enums\CheckoutStatus;
use App\Modules\Checkout\Exceptions\CheckoutRefused;
use App\Modules\Commission\Models\CommissionRule;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Enums\ReservationStatus;
use App\Modules\Inventory\Models\InventoryReservation;
use App\Modules\Orders\Enums\MarketplaceOrderStatus;
use App\Modules\Orders\Enums\SellerOrderStatus;
use App\Modules\Orders\Models\MarketplaceOrder;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderStatusHistory;
use App\Modules\Orders\Models\SellerOrder;
use App\Modules\Sellers\Enums\SellerStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Feature\Cart\BuildsCommerceFixtures;
use Tests\TestCase;

/**
 * One checkout, one order, one seller order per seller.
 *
 *   VC-24081
 *   ├── VC-24081-01  Seller A
 *   └── VC-24081-02  Seller B
 *
 * §18 to §24. The parent is what the customer pays for once; each child is
 * what one seller ships and is paid for, with its own status, commission
 * and fulfilment clock — and every financial figure on it is frozen at
 * placement, never recomputed.
 */
final class OrderCreationTest extends TestCase
{
    use BuildsCommerceFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CommissionRule::factory()->create();
    }

    #[Test]
    public function one_checkout_across_two_sellers_produces_one_order_with_two_seller_orders(): void
    {
        ['offer' => $a] = $this->sellableOffer('Kettle', priceMinor: 4_000);
        ['offer' => $b] = $this->sellableOffer('Lamp', priceMinor: 6_000);

        $cart = $this->cart();
        app(AddOfferToCart::class)($cart, $a->public_id, 1);
        app(AddOfferToCart::class)($cart, $b->public_id, 2);

        $order = $this->place($cart);

        $this->assertSame(1, MarketplaceOrder::query()->count());
        $this->assertCount(2, $order->sellerOrders);
        $this->assertSame(16_000, $order->items_total_minor);
        $this->assertSame(16_000, $order->grand_total_minor);
    }

    #[Test]
    public function seller_orders_are_numbered_within_their_parent(): void
    {
        ['offer' => $a] = $this->sellableOffer('Kettle');
        ['offer' => $b] = $this->sellableOffer('Lamp');

        $cart = $this->cart();
        app(AddOfferToCart::class)($cart, $a->public_id, 1);
        app(AddOfferToCart::class)($cart, $b->public_id, 1);

        $order = $this->place($cart);

        $references = SellerOrder::query()
            ->where('marketplace_order_id', $order->id)
            ->orderBy('position')
            ->pluck('reference')
            ->all();

        $this->assertSame([$order->reference.'-01', $order->reference.'-02'], $references);
    }

    #[Test]
    public function two_lines_from_one_seller_are_one_seller_order(): void
    {
        ['offer' => $a, 'seller' => $seller, 'store' => $store] = $this->sellableOffer('Kettle');
        ['offer' => $b] = $this->sellableOffer('Lamp', seller: $seller, store: $store);

        $cart = $this->cart();
        app(AddOfferToCart::class)($cart, $a->public_id, 1);
        app(AddOfferToCart::class)($cart, $b->public_id, 1);

        $order = $this->place($cart);

        // One seller is one parcel, one status and one payout — however
        // many of their listings are in the basket.
        $this->assertCount(1, $order->sellerOrders);
        $this->assertSame(2, OrderItem::query()->count());
    }

    #[Test]
    public function every_item_carries_its_own_immutable_price_and_commission_snapshot(): void
    {
        ['offer' => $offer, 'product' => $product, 'store' => $store] = $this->sellableOffer(
            'Aeris Cordless Kettle',
            priceMinor: 9_900,
        );

        $cart = $this->cart();
        app(AddOfferToCart::class)($cart, $offer->public_id, 2);

        $this->place($cart);

        /** @var OrderItem $item */
        $item = OrderItem::query()->firstOrFail();

        $this->assertSame(9_900, $item->unit_price_snapshot_minor);
        $this->assertSame(19_800, $item->line_total_minor);
        $this->assertSame('Aeris Cordless Kettle', $item->product_title);
        $this->assertSame($product->slug, $item->product_slug_snapshot);
        $this->assertSame($store->name, $item->store_name_snapshot);
        $this->assertSame('12.00', $item->commission_rate_snapshot);

        // The split is exact by construction: the earning is the
        // remainder, never computed independently.
        $this->assertSame(
            $item->line_total_minor,
            $item->commission_amount_minor + $item->seller_earning_amount_minor,
        );
    }

    #[Test]
    public function repricing_the_offer_afterwards_leaves_the_order_untouched(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 5_000);

        $cart = $this->cart();
        app(AddOfferToCart::class)($cart, $offer->public_id, 1);
        $order = $this->place($cart);

        $offer->forceFill(['price_minor' => 25_000])->save();

        $this->assertSame(5_000, (int) OrderItem::query()->value('unit_price_snapshot_minor'));
        $this->assertSame(5_000, $order->refresh()->grand_total_minor);
    }

    #[Test]
    public function a_seller_orders_totals_are_rolled_up_from_its_items(): void
    {
        ['offer' => $a, 'seller' => $seller, 'store' => $store] = $this->sellableOffer('Kettle', priceMinor: 3_000);
        ['offer' => $b] = $this->sellableOffer('Lamp', priceMinor: 4_500, seller: $seller, store: $store);

        $cart = $this->cart();
        app(AddOfferToCart::class)($cart, $a->public_id, 2);
        app(AddOfferToCart::class)($cart, $b->public_id, 1);

        $this->place($cart);

        /** @var SellerOrder $sellerOrder */
        $sellerOrder = SellerOrder::query()->firstOrFail();
        $items = OrderItem::query()->where('seller_order_id', $sellerOrder->id)->get();

        $this->assertSame(10_500, $sellerOrder->items_total_minor);
        $this->assertSame((int) $items->sum('line_total_minor'), $sellerOrder->items_total_minor);
        $this->assertSame((int) $items->sum('commission_amount_minor'), $sellerOrder->commission_total_minor);
        $this->assertSame((int) $items->sum('seller_earning_amount_minor'), $sellerOrder->seller_earning_total_minor);
    }

    #[Test]
    public function the_parent_totals_equal_the_sum_of_the_children(): void
    {
        config()->set('veritas.checkout.shipping_per_seller_order_minor', 500);

        ['offer' => $a] = $this->sellableOffer('Kettle', priceMinor: 2_000);
        ['offer' => $b] = $this->sellableOffer('Lamp', priceMinor: 3_000);

        $cart = $this->cart();
        app(AddOfferToCart::class)($cart, $a->public_id, 1);
        app(AddOfferToCart::class)($cart, $b->public_id, 1);

        $order = $this->place($cart);

        $children = SellerOrder::query()->where('marketplace_order_id', $order->id)->get();

        // §24's reconciliation, asserted rather than reported.
        $this->assertSame((int) $children->sum('items_total_minor'), $order->items_total_minor);
        $this->assertSame((int) $children->sum('shipping_total_minor'), $order->shipping_total_minor);
        $this->assertSame((int) $children->sum('order_total_minor'), $order->grand_total_minor);
        $this->assertSame(1_000, $order->shipping_total_minor);
    }

    #[Test]
    public function the_database_refuses_a_seller_order_whose_totals_do_not_add_up(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $cart = $this->cart();
        app(AddOfferToCart::class)($cart, $offer->public_id, 1);
        $this->place($cart);

        // The constraint does not depend on anybody remembering to
        // recompute a rollup correctly.
        $this->expectExceptionMessage('seller_orders_total_is_exact');

        DB::table('seller_orders')->update(['order_total_minor' => 1]);
    }

    #[Test]
    public function the_database_refuses_a_commission_split_that_does_not_add_up(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $cart = $this->cart();
        app(AddOfferToCart::class)($cart, $offer->public_id, 1);
        $this->place($cart);

        $this->expectExceptionMessage('seller_orders_commission_split_is_exact');

        DB::table('seller_orders')->update(['commission_total_minor' => 0]);
    }

    #[Test]
    public function the_order_begins_pending_payment_and_still_only_holds_its_stock(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 10);
        $cart = $this->cart();
        app(AddOfferToCart::class)($cart, $offer->public_id, 3);

        $order = $this->place($cart);

        $this->assertSame(MarketplaceOrderStatus::PendingPayment, $order->status);
        $this->assertSame(
            SellerOrderStatus::PendingPayment,
            SellerOrder::query()->firstOrFail()->status,
        );

        // The M5 boundary, already drawn: stock is held, not sold, until
        // payment captures.
        $balance = DB::table('inventory_balances')->where('offer_id', $offer->id)->first();
        $this->assertNotNull($balance);
        $this->assertSame(10, (int) $balance->on_hand, 'Nothing has left the shelf yet.');
        $this->assertSame(3, (int) $balance->reserved);
        $this->assertSame(
            ReservationStatus::Held,
            InventoryReservation::query()->firstOrFail()->status,
        );
    }

    #[Test]
    public function the_order_carries_the_reference_that_finds_its_holds(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $cart = $this->cart();
        app(AddOfferToCart::class)($cart, $offer->public_id, 1);

        $attempt = app(StartCheckout::class)($cart, 'key-1', $this->address());
        $order = app(PlaceOrder::class)($attempt);

        $this->assertSame($attempt->reservationReference(), $order->reservation_reference);
        $this->assertSame(
            1,
            InventoryReservation::query()->where('reference', $order->reservation_reference)->count(),
        );
        $this->assertNotNull($order->payment_expires_at);
    }

    #[Test]
    public function placing_the_same_attempt_twice_returns_the_same_order(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $cart = $this->cart();
        app(AddOfferToCart::class)($cart, $offer->public_id, 1);

        $attempt = app(StartCheckout::class)($cart, 'key-1', $this->address());

        $first = app(PlaceOrder::class)($attempt);
        $second = app(PlaceOrder::class)($attempt->refresh());

        // §16: a retried checkout must not become two orders.
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, MarketplaceOrder::query()->count());
        $this->assertSame(1, SellerOrder::query()->count());
        $this->assertSame(1, InventoryReservation::query()->count());
    }

    #[Test]
    public function the_attempt_and_the_cart_are_closed_by_a_successful_order(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $cart = $this->cart();
        app(AddOfferToCart::class)($cart, $offer->public_id, 1);

        $attempt = app(StartCheckout::class)($cart, 'key-1', $this->address());
        $order = app(PlaceOrder::class)($attempt);

        $attempt->refresh();

        $this->assertSame(CheckoutStatus::Completed, $attempt->status);
        $this->assertSame($order->id, $attempt->marketplace_order_id);
        $this->assertNotNull($attempt->completed_at);

        // Kept, never reused: the converted cart is the evidence behind
        // the order.
        $this->assertSame(CartStatus::Converted, $cart->refresh()->status);
    }

    #[Test]
    public function a_seller_suspended_between_reserving_and_paying_stops_the_order(): void
    {
        ['offer' => $offer, 'seller' => $seller] = $this->sellableOffer();
        $cart = $this->cart();
        app(AddOfferToCart::class)($cart, $offer->public_id, 1);

        $attempt = app(StartCheckout::class)($cart, 'key-1', $this->address());

        // The reservation guarantees the units are still there. It
        // guarantees nothing about whether they may still be sold.
        $seller->forceFill(['status' => SellerStatus::Suspended->value])->save();

        try {
            app(PlaceOrder::class)($attempt);
            $this->fail('An order must not be created for a suspended seller.');
        } catch (CheckoutRefused $e) {
            $this->assertSame('cart_not_buyable', $e->reason);
        }

        $this->assertSame(0, MarketplaceOrder::query()->count());
    }

    #[Test]
    public function a_price_that_moved_after_the_quote_stops_the_order(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 5_000);
        $cart = $this->cart();
        app(AddOfferToCart::class)($cart, $offer->public_id, 1);

        $attempt = app(StartCheckout::class)($cart, 'key-1', $this->address());

        $offer->forceFill(['price_minor' => 5_900])->save();

        try {
            app(PlaceOrder::class)($attempt);
            $this->fail('An order written for a different amount is not the order the customer placed.');
        } catch (CheckoutRefused $e) {
            $this->assertSame('price_moved', $e->reason);
        }

        $this->assertSame(0, MarketplaceOrder::query()->count());
    }

    #[Test]
    public function a_price_that_moved_downward_stops_the_order_too(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 5_000);
        $cart = $this->cart();
        app(AddOfferToCart::class)($cart, $offer->public_id, 1);

        $attempt = app(StartCheckout::class)($cart, 'key-1', $this->address());
        $offer->forceFill(['price_minor' => 4_000])->save();

        // Cheaper is still not what they agreed to, and quietly charging
        // less is quietly charging the seller.
        $this->expectException(CheckoutRefused::class);
        app(PlaceOrder::class)($attempt);
    }

    #[Test]
    public function an_expired_attempt_cannot_become_an_order(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $cart = $this->cart();
        app(AddOfferToCart::class)($cart, $offer->public_id, 1);

        $attempt = app(StartCheckout::class)($cart, 'key-1', $this->address());
        $attempt->forceFill(['expires_at' => now()->subMinute()])->save();

        try {
            app(PlaceOrder::class)($attempt);
            $this->fail('A timed-out checkout must not still produce an order.');
        } catch (CheckoutRefused $e) {
            $this->assertSame('attempt_expired', $e->reason);
        }
    }

    #[Test]
    public function the_placement_is_recorded_in_the_append_only_history(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $cart = $this->cart();
        app(AddOfferToCart::class)($cart, $offer->public_id, 1);

        $order = $this->place($cart);

        $parent = OrderStatusHistory::query()->where('marketplace_order_id', $order->id)->firstOrFail();
        $child = OrderStatusHistory::query()->whereNotNull('seller_order_id')->firstOrFail();

        // Every transition is its own row with an actor, so a dispute can
        // be reconstructed.
        $this->assertNull($parent->from_status);
        $this->assertSame('pending_payment', $parent->to_status);
        $this->assertSame('system', $parent->actor_type);
        $this->assertSame('pending_payment', $child->to_status);
    }

    #[Test]
    public function the_order_item_snapshot_cannot_be_rewritten(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $cart = $this->cart();
        app(AddOfferToCart::class)($cart, $offer->public_id, 1);
        $this->place($cart);

        $item = OrderItem::query()->firstOrFail();

        // A well-meaning "recalculate totals" job fails loudly rather than
        // quietly rewriting history.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('financial snapshot');

        $item->update(['line_total_minor' => 1]);
    }

    #[Test]
    public function an_authenticated_order_carries_the_customer_and_their_email(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.test']);
        ['offer' => $offer] = $this->sellableOffer();

        $cart = $this->cart(userId: $user->id);
        app(AddOfferToCart::class)($cart, $offer->public_id, 1);

        $attempt = app(StartCheckout::class)($cart, 'key-1', $this->address(), $user->id);
        $order = app(PlaceOrder::class)($attempt);

        $this->assertSame($user->id, $order->user_id);
        $this->assertSame('ada@example.test', $order->email);
    }

    #[Test]
    public function a_guest_order_records_the_email_it_was_given(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $cart = $this->cart();
        app(AddOfferToCart::class)($cart, $offer->public_id, 1);

        $attempt = app(StartCheckout::class)($cart, 'key-1', $this->address(), null, 'guest@example.test');
        $order = app(PlaceOrder::class)($attempt);

        // A receipt goes to a person, a parcel goes to a place.
        $this->assertNull($order->user_id);
        $this->assertSame('guest@example.test', $order->email);
    }

    #[Test]
    public function the_shipping_address_is_copied_onto_the_order_not_linked(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $cart = $this->cart();
        app(AddOfferToCart::class)($cart, $offer->public_id, 1);

        $order = $this->place($cart);

        $this->assertSame('Ada Lovelace', $order->ship_name);
        $this->assertSame('London', $order->ship_city);
        $this->assertSame('GB', $order->ship_country);
        $this->assertNull($order->ship_state, 'A country with no state must still be shippable.');
    }

    #[Test]
    public function seller_orders_are_numbered_in_the_same_order_the_cart_grouped_them(): void
    {
        ['offer' => $a, 'seller' => $sellerA] = $this->sellableOffer('Kettle');
        ['offer' => $b, 'seller' => $sellerB] = $this->sellableOffer('Lamp');

        $cart = $this->cart();
        // Added in the opposite order to the sellers' ids.
        app(AddOfferToCart::class)($cart, $b->public_id, 1);
        app(AddOfferToCart::class)($cart, $a->public_id, 1);

        $order = $this->place($cart);

        $positions = SellerOrder::query()
            ->where('marketplace_order_id', $order->id)
            ->orderBy('position')
            ->pluck('seller_account_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        // Deterministic: the same basket always numbers the same way,
        // whatever order the customer added things in.
        $this->assertSame([min($sellerA->id, $sellerB->id), max($sellerA->id, $sellerB->id)], $positions);
    }

    private function place(Cart $cart, string $key = 'key-1'): MarketplaceOrder
    {
        return app(PlaceOrder::class)(
            app(StartCheckout::class)($cart, $key, $this->address(), null, 'buyer@example.test'),
        );
    }

    private function address(): ShippingAddress
    {
        return new ShippingAddress(
            name: 'Ada Lovelace',
            line1: '12 Analytical Way',
            line2: null,
            city: 'London',
            state: null,
            postcode: 'EC1A 1BB',
            country: 'GB',
        );
    }
}
