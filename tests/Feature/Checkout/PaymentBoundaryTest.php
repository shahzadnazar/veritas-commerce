<?php

declare(strict_types=1);

namespace Tests\Feature\Checkout;

use App\Modules\Cart\Actions\AddOfferToCart;
use App\Modules\Cart\Models\Cart;
use App\Modules\Checkout\Actions\PlaceOrder;
use App\Modules\Checkout\Actions\StartCheckout;
use App\Modules\Checkout\Data\ShippingAddress;
use App\Modules\Checkout\Enums\CheckoutStatus;
use App\Modules\Checkout\Jobs\ExpireCheckoutAttempts;
use App\Modules\Commission\Models\CommissionRule;
use App\Modules\Inventory\Actions\ReleaseReservation;
use App\Modules\Inventory\Enums\InventoryMovementReason;
use App\Modules\Inventory\Enums\ReservationStatus;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\InventoryReservation;
use App\Modules\Orders\Actions\CancelUnpaidOrder;
use App\Modules\Orders\Actions\MarkOrderPaid;
use App\Modules\Orders\Enums\MarketplaceOrderStatus;
use App\Modules\Orders\Enums\SellerOrderStatus;
use App\Modules\Orders\Jobs\ExpireUnpaidOrders;
use App\Modules\Orders\Models\MarketplaceOrder;
use App\Modules\Orders\Models\OrderStatusHistory;
use App\Modules\Orders\Models\SellerOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsCommerceFixtures;
use Tests\TestCase;

/**
 * Held, then either sold or given back — and never anything in between.
 *
 * §29 and §30. An order created pending payment is holding a seller's
 * units without having bought them. Exactly one of two things must
 * eventually happen to every one of those holds, and both have to survive
 * being run twice, because a sweep is queued and a payment provider's
 * webhook is delivered more than once.
 */
final class PaymentBoundaryTest extends TestCase
{
    use BuildsCommerceFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CommissionRule::factory()->create();
    }

    #[Test]
    public function payment_turns_the_hold_into_a_sale_in_one_movement(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 10);
        $order = $this->placedOrder($offer->public_id, 3);

        $this->assertTrue(app(MarkOrderPaid::class)($order));

        $balance = DB::table('inventory_balances')->where('offer_id', $offer->id)->first();
        $this->assertNotNull($balance);

        // Both quantities fall together, so availability cannot flicker
        // upward between two writes and let a concurrent hold take stock
        // that is already sold.
        $this->assertSame(7, (int) $balance->on_hand);
        $this->assertSame(0, (int) $balance->reserved);
        $this->assertSame(7, (int) $balance->available);

        $movement = InventoryMovement::query()
            ->where('reason', InventoryMovementReason::SaleCompleted->value)
            ->firstOrFail();

        $this->assertSame(-3, (int) $movement->on_hand_change);
        $this->assertSame(-3, (int) $movement->reserved_change);
    }

    #[Test]
    public function a_paid_order_and_its_seller_orders_both_move(): void
    {
        ['offer' => $a] = $this->sellableOffer('Kettle');
        ['offer' => $b] = $this->sellableOffer('Lamp');

        $cart = $this->cart();
        app(AddOfferToCart::class)($cart, $a->public_id, 1);
        app(AddOfferToCart::class)($cart, $b->public_id, 1);
        $order = $this->place($cart);

        app(MarkOrderPaid::class)($order);

        $this->assertSame(MarketplaceOrderStatus::Paid, $order->refresh()->status);
        $this->assertSame(
            [SellerOrderStatus::Paid, SellerOrderStatus::Paid],
            SellerOrder::query()->orderBy('position')->get()->pluck('status')->all(),
        );
        $this->assertNull($order->payment_expires_at, 'Nothing sweeps a paid order.');
    }

    #[Test]
    public function each_sale_is_filed_against_the_seller_order_it_belongs_to(): void
    {
        ['offer' => $a] = $this->sellableOffer('Kettle');
        ['offer' => $b] = $this->sellableOffer('Lamp');

        $cart = $this->cart();
        app(AddOfferToCart::class)($cart, $a->public_id, 1);
        app(AddOfferToCart::class)($cart, $b->public_id, 1);
        $order = $this->place($cart);

        app(MarkOrderPaid::class)($order);

        $movements = InventoryMovement::query()
            ->where('reason', InventoryMovementReason::SaleCompleted->value)
            ->get();

        // The holds share one reference but the sales belong to different
        // sellers; a movement filed against the wrong one misattributes
        // the sale everywhere downstream of it.
        $this->assertCount(2, $movements);
        $this->assertCount(2, $movements->pluck('seller_order_id')->unique());
        $this->assertNotContains(null, $movements->pluck('seller_order_id')->all());
    }

    #[Test]
    public function a_payment_webhook_delivered_twice_sells_the_units_once(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 5);
        $order = $this->placedOrder($offer->public_id, 2);

        $this->assertTrue(app(MarkOrderPaid::class)($order));
        $this->assertFalse(app(MarkOrderPaid::class)($order->refresh()));

        $balance = DB::table('inventory_balances')->where('offer_id', $offer->id)->first();
        $this->assertNotNull($balance);
        $this->assertSame(3, (int) $balance->on_hand, 'Every provider eventually delivers a webhook twice.');
        $this->assertSame(1, InventoryMovement::query()
            ->where('reason', InventoryMovementReason::SaleCompleted->value)->count());
    }

    #[Test]
    public function an_unpaid_order_gives_its_stock_back_when_the_window_closes(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 5);
        $order = $this->placedOrder($offer->public_id, 3);

        $order->forceFill(['payment_expires_at' => now()->subMinute()])->save();

        app(ExpireUnpaidOrders::class)->handle(app(CancelUnpaidOrder::class));

        $order->refresh();

        $this->assertSame(MarketplaceOrderStatus::Cancelled, $order->status);
        $this->assertNotNull($order->cancelled_at);

        $balance = DB::table('inventory_balances')->where('offer_id', $offer->id)->first();
        $this->assertNotNull($balance);
        $this->assertSame(5, (int) $balance->on_hand, 'Nothing physically moved.');
        $this->assertSame(0, (int) $balance->reserved);
        $this->assertSame(5, (int) $balance->available, 'The next customer can buy all five again.');
    }

    #[Test]
    public function cancelling_an_unpaid_order_cancels_every_seller_order_under_it(): void
    {
        ['offer' => $a] = $this->sellableOffer('Kettle');
        ['offer' => $b] = $this->sellableOffer('Lamp');

        $cart = $this->cart();
        app(AddOfferToCart::class)($cart, $a->public_id, 1);
        app(AddOfferToCart::class)($cart, $b->public_id, 1);
        $order = $this->place($cart);

        app(CancelUnpaidOrder::class)($order);

        // A marketplace order half cancelled is not a state a customer, a
        // seller or a payment provider can make sense of.
        $this->assertSame(
            [SellerOrderStatus::Cancelled, SellerOrderStatus::Cancelled],
            SellerOrder::query()->orderBy('position')->get()->pluck('status')->all(),
        );
    }

    #[Test]
    public function cancelling_twice_releases_the_stock_once(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 4);
        $order = $this->placedOrder($offer->public_id, 2);

        $this->assertTrue(app(CancelUnpaidOrder::class)($order));
        $this->assertFalse(app(CancelUnpaidOrder::class)($order->refresh()));

        $balance = DB::table('inventory_balances')->where('offer_id', $offer->id)->first();
        $this->assertNotNull($balance);
        $this->assertSame(0, (int) $balance->reserved);
        $this->assertSame(4, (int) $balance->available, 'A double release must not invent stock.');
    }

    #[Test]
    public function a_paid_order_is_never_swept(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 5);
        $order = $this->placedOrder($offer->public_id, 2);

        app(MarkOrderPaid::class)($order);

        // Deliberately backdated: the sweep must key off status, not only
        // off the clock.
        DB::table('marketplace_orders')->update(['payment_expires_at' => now()->subHour()]);

        app(ExpireUnpaidOrders::class)->handle(app(CancelUnpaidOrder::class));

        $this->assertSame(MarketplaceOrderStatus::Paid, $order->refresh()->status);
        $this->assertSame(3, (int) DB::table('inventory_balances')->where('offer_id', $offer->id)->value('on_hand'));
    }

    #[Test]
    public function an_order_still_inside_its_window_is_left_alone(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 5);
        $order = $this->placedOrder($offer->public_id, 2);

        app(ExpireUnpaidOrders::class)->handle(app(CancelUnpaidOrder::class));

        $this->assertSame(MarketplaceOrderStatus::PendingPayment, $order->refresh()->status);
        $this->assertSame(2, (int) DB::table('inventory_balances')->where('offer_id', $offer->id)->value('reserved'));
    }

    #[Test]
    public function the_cancellation_is_recorded_in_the_append_only_history(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $order = $this->placedOrder($offer->public_id, 1);

        app(CancelUnpaidOrder::class)($order, 'Payment window closed.');

        $row = OrderStatusHistory::query()
            ->where('marketplace_order_id', $order->id)
            ->where('to_status', MarketplaceOrderStatus::Cancelled->value)
            ->firstOrFail();

        $this->assertSame('pending_payment', $row->from_status);
        $this->assertSame('system', $row->actor_type);
        $this->assertSame('Payment window closed.', $row->note);
    }

    #[Test]
    public function an_abandoned_checkout_that_never_became_an_order_is_swept_too(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 6);
        $cart = $this->cart();
        app(AddOfferToCart::class)($cart, $offer->public_id, 4);

        $attempt = app(StartCheckout::class)($cart, 'key-1', $this->address());
        $attempt->forceFill(['expires_at' => now()->subMinute()])->save();

        app(ExpireCheckoutAttempts::class)->handle(app(ReleaseReservation::class));

        $attempt->refresh();

        // The customer who reached the payment page, held the last units
        // and closed the tab before an order existed at all.
        $this->assertSame(CheckoutStatus::Expired, $attempt->status);
        $this->assertSame(6, (int) DB::table('inventory_balances')->where('offer_id', $offer->id)->value('available'));
        $this->assertSame(
            ReservationStatus::Released,
            InventoryReservation::query()->firstOrFail()->status,
        );
    }

    #[Test]
    public function an_attempt_that_became_an_order_is_left_to_the_order_sweep(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 5);
        $cart = $this->cart();
        app(AddOfferToCart::class)($cart, $offer->public_id, 2);

        $attempt = app(StartCheckout::class)($cart, 'key-1', $this->address());
        app(PlaceOrder::class)($attempt);

        DB::table('checkout_attempts')->update(['expires_at' => now()->subMinute()]);

        app(ExpireCheckoutAttempts::class)->handle(app(ReleaseReservation::class));

        // Two sweeps must not both claim the same holds — one owns the
        // attempt, the other owns the order.
        $this->assertSame(CheckoutStatus::Completed, $attempt->refresh()->status);
        $this->assertSame(2, (int) DB::table('inventory_balances')->where('offer_id', $offer->id)->value('reserved'));
    }

    #[Test]
    public function sweeping_the_same_attempt_twice_releases_once(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 6);
        $cart = $this->cart();
        app(AddOfferToCart::class)($cart, $offer->public_id, 3);

        $attempt = app(StartCheckout::class)($cart, 'key-1', $this->address());
        $attempt->forceFill(['expires_at' => now()->subMinute()])->save();

        $release = app(ReleaseReservation::class);
        app(ExpireCheckoutAttempts::class)->handle($release);
        app(ExpireCheckoutAttempts::class)->handle($release);

        $this->assertSame(6, (int) DB::table('inventory_balances')->where('offer_id', $offer->id)->value('available'));
        $this->assertSame(1, InventoryMovement::query()
            ->where('reason', InventoryMovementReason::ReservationRelease->value)->count());
    }

    #[Test]
    public function a_cancelled_order_can_never_be_marked_paid(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 5);
        $order = $this->placedOrder($offer->public_id, 2);

        app(CancelUnpaidOrder::class)($order);

        // The webhook arrives after the sweep. It must not sell units the
        // marketplace has already put back on the shelf.
        $this->assertFalse(app(MarkOrderPaid::class)($order->refresh()));
        $this->assertSame(5, (int) DB::table('inventory_balances')->where('offer_id', $offer->id)->value('on_hand'));
        $this->assertSame(MarketplaceOrderStatus::Cancelled, $order->refresh()->status);
    }

    #[Test]
    public function releasing_an_orders_stock_makes_it_available_to_the_next_customer(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 1);

        $mine = $this->cart(sessionToken: 'browser-a');
        app(AddOfferToCart::class)($mine, $offer->public_id, 1);
        $order = $this->place($mine, 'mine');

        app(CancelUnpaidOrder::class)($order);

        // The whole point of the window: an abandoned checkout must not
        // take a seller's last unit off the market permanently.
        $theirs = $this->cart(sessionToken: 'browser-b');
        app(AddOfferToCart::class)($theirs, $offer->public_id, 1);

        $this->assertSame(
            MarketplaceOrderStatus::PendingPayment,
            $this->place($theirs, 'theirs')->status,
        );
    }

    private function placedOrder(string $offerPublicId, int $quantity): MarketplaceOrder
    {
        $cart = $this->cart();
        app(AddOfferToCart::class)($cart, $offerPublicId, $quantity);

        return $this->place($cart);
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
