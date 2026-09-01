<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Modules\Commission\Models\CommissionRule;
use App\Modules\Identity\Models\User;
use App\Modules\Offers\Enums\OfferStatus;
use App\Modules\Orders\Models\MarketplaceOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsCommerceFixtures;
use Tests\TestCase;

/**
 * A customer's order history, and who may read it.
 *
 * Two things are being proved here. That an order describes itself from
 * its own snapshots, so a seller cannot rewrite a receipt by editing a
 * listing. And that a reference is a lookup key rather than an
 * authorization — the number is short, human and quotable, which is
 * exactly why it must never be the thing that grants access.
 */
final class CustomerOrderScreensTest extends TestCase
{
    use BuildsCommerceFixtures;
    use BuildsPlacedOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CommissionRule::factory()->create();
    }

    #[Test]
    public function the_list_shows_a_customers_own_orders_only(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 20);
        $mine = User::factory()->create();
        $theirs = User::factory()->create();

        $myOrder = $this->placeOrder([$offer], $mine->id);
        $this->placeOrder([$offer], $theirs->id);

        $this->asUser($mine)->get('/account/orders')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Account/Orders/Index')
                ->has('orders.data', 1)
                ->where('orders.data.0.reference', $myOrder->reference));
    }

    #[Test]
    public function the_detail_page_renders_from_the_orders_own_snapshots(): void
    {
        ['offer' => $offer, 'product' => $product, 'store' => $store] = $this->sellableOffer(
            'Aeris Cordless Kettle',
            priceMinor: 9_900,
        );
        $user = User::factory()->create();
        $order = $this->placeOrder([[$offer, 2]], $user->id);

        $this->asUser($user)->get('/account/orders/'.$order->reference)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Account/Orders/Show')
                ->where('order.reference', $order->reference)
                ->where('order.grandTotal.minor', 19_800)
                ->where('order.sellerOrders.0.items.0.productTitle', 'Aeris Cordless Kettle')
                ->where('order.sellerOrders.0.items.0.storeName', $store->name)
                ->where('order.sellerOrders.0.items.0.productSlug', $product->slug)
                ->where('order.sellerOrders.0.items.0.unitPrice.minor', 9_900));
    }

    #[Test]
    public function the_receipt_does_not_change_when_the_listing_does(): void
    {
        ['offer' => $offer, 'product' => $product, 'store' => $store] = $this->sellableOffer(
            'Aeris Cordless Kettle',
            priceMinor: 9_900,
        );
        $user = User::factory()->create();
        $order = $this->placeOrder([$offer], $user->id);

        // Everything about the listing moves afterwards.
        $offer->forceFill(['price_minor' => 25_000, 'seller_sku' => 'NEW-SKU'])->save();
        $product->forceFill(['title' => 'Renamed Kettle'])->save();
        $store->forceFill(['name' => 'Renamed Store'])->save();
        DB::table('commission_rules')->update(['rate_percent' => '30.00']);

        $this->asUser($user)->get('/account/orders/'.$order->reference)
            ->assertInertia(fn ($page) => $page
                ->where('order.sellerOrders.0.items.0.productTitle', 'Aeris Cordless Kettle')
                ->where('order.sellerOrders.0.items.0.unitPrice.minor', 9_900)
                ->where('order.grandTotal.minor', 9_900));
    }

    #[Test]
    public function the_detail_page_shows_the_address_the_order_was_sent_to(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $user = User::factory()->create();
        $order = $this->placeOrder([$offer], $user->id);

        $this->asUser($user)->get('/account/orders/'.$order->reference)
            ->assertInertia(fn ($page) => $page
                ->where('order.shippingAddress.line1', '12 Analytical Way')
                // A country with no state is not missing data.
                ->where('order.shippingAddress.state', null));
    }

    #[Test]
    public function a_multi_seller_order_keeps_its_parent_and_child_shape(): void
    {
        ['offer' => $a] = $this->sellableOffer('Kettle', priceMinor: 4_000);
        ['offer' => $b] = $this->sellableOffer('Lamp', priceMinor: 6_000);
        $user = User::factory()->create();

        $order = $this->placeOrder([$a, $b], $user->id);

        $this->asUser($user)->get('/account/orders/'.$order->reference)
            ->assertInertia(fn ($page) => $page
                ->has('order.sellerOrders', 2)
                ->where('order.sellerOrders.0.reference', $order->reference.'-01')
                ->where('order.sellerOrders.1.reference', $order->reference.'-02'));
    }

    #[Test]
    public function a_customer_never_sees_the_platforms_commission(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $user = User::factory()->create();
        $order = $this->placeOrder([$offer], $user->id);

        $response = $this->asUser($user)->get('/account/orders/'.$order->reference);

        // What the platform took from the seller is between the platform
        // and the seller.
        $response->assertInertia(fn ($page) => $page
            ->missing('order.sellerOrders.0.commissionTotal')
            ->missing('order.sellerOrders.0.items.0.commission'));
    }

    #[Test]
    public function another_customers_order_is_a_404_not_a_403(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 20);
        $mine = User::factory()->create();
        $theirs = User::factory()->create();

        $theirOrder = $this->placeOrder([$offer], $theirs->id);

        // 403 would confirm the order exists, which is itself something a
        // stranger should not learn from a guessed reference.
        $this->asUser($mine)->get('/account/orders/'.$theirOrder->reference)->assertNotFound();
    }

    #[Test]
    public function a_guessed_reference_from_the_sequence_finds_nothing(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 20);
        $mine = User::factory()->create();
        $theirs = User::factory()->create();

        $mineOrder = $this->placeOrder([$offer], $mine->id);
        $theirOrder = $this->placeOrder([$offer], $theirs->id);

        // The references are consecutive by design — that is what makes
        // them quotable, and why they cannot be the authorization.
        $this->assertNotSame($mineOrder->reference, $theirOrder->reference);
        $this->asUser($mine)->get('/account/orders/'.$theirOrder->reference)->assertNotFound();
    }

    #[Test]
    public function a_guest_order_is_not_reachable_by_a_signed_in_stranger(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $guestOrder = $this->placeOrder([$offer], null);
        $stranger = User::factory()->create();

        $this->asUser($stranger)->get('/account/orders/'.$guestOrder->reference)->assertNotFound();
    }

    #[Test]
    public function order_history_requires_signing_in(): void
    {
        $this->get('/account/orders')->assertRedirect('/login');
        $this->get('/account/orders/VC-1')->assertRedirect('/login');
    }

    #[Test]
    public function the_order_pages_are_never_indexable(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $user = User::factory()->create();
        $order = $this->placeOrder([$offer], $user->id);

        $this->asUser($user)->get('/account/orders')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->asUser($user)->get('/account/orders/'.$order->reference)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    #[Test]
    public function the_payment_pending_page_is_honest_about_what_has_happened(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 7_500);
        $user = User::factory()->create();
        $order = $this->placeOrder([$offer], $user->id);

        $this->asUser($user)->get('/checkout/'.$order->reference.'/payment')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Checkout/PaymentPending')
                ->where('paymentStatus.state', 'awaiting_payment')
                ->where(
                    'paymentStatus.headline',
                    'Your order has been prepared, but payment has not yet been completed.',
                )
                ->where('order.status', 'pending_payment')
                ->where('order.grandTotal.minor', 7_500));
    }

    #[Test]
    public function another_customers_payment_page_is_not_reachable(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 20);
        $mine = User::factory()->create();
        $theirs = User::factory()->create();

        $theirOrder = $this->placeOrder([$offer], $theirs->id);

        $this->asUser($mine)->get('/checkout/'.$theirOrder->reference.'/payment')->assertNotFound();
        $this->get('/checkout/'.$theirOrder->reference.'/payment')->assertNotFound();
    }

    #[Test]
    public function a_guest_can_reach_the_payment_page_for_the_order_they_just_placed(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 3_000);

        $this->post('/cart', ['offer' => $offer->public_id]);
        $this->post('/checkout', [
            'idempotency_key' => 'abcdefgh12345678',
            'email' => 'guest@example.test',
            'name' => 'Ada Lovelace',
            'line1' => '12 Analytical Way',
            'city' => 'London',
            'postcode' => 'EC1A 1BB',
            'country' => 'GB',
        ]);

        $order = MarketplaceOrder::query()->firstOrFail();

        // Matched through the cart the session still points at — the only
        // durable link a guest has to their own order.
        $this->get('/checkout/'.$order->reference.'/payment')->assertOk();
    }

    #[Test]
    public function an_unavailable_listing_does_not_break_an_old_order(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $user = User::factory()->create();
        $order = $this->placeOrder([$offer], $user->id);

        $offer->forceFill(['status' => OfferStatus::Archived->value])->save();

        // The order reads nothing through the offer, so archiving it
        // cannot make a receipt unrenderable.
        $this->asUser($user)->get('/account/orders/'.$order->reference)->assertOk();
    }

    #[Test]
    public function the_order_screens_cost_a_fixed_number_of_queries(): void
    {
        ['offer' => $a] = $this->sellableOffer('Kettle');
        ['offer' => $b] = $this->sellableOffer('Lamp');
        ['offer' => $c] = $this->sellableOffer('Rug');
        $user = User::factory()->create();

        $small = $this->placeOrder([$a], $user->id);
        $large = $this->placeOrder([$a, $b, $c], $user->id);

        $one = $this->countQueries(fn () => $this->asUser($user)->get('/account/orders/'.$small->reference));
        $three = $this->countQueries(fn () => $this->asUser($user)->get('/account/orders/'.$large->reference));

        // Three sellers must not be three times the queries: the reader
        // takes the seller orders and every item across them in one go.
        $this->assertSame($one, $three, "One seller took {$one} queries, three took {$three}.");
    }

    #[Test]
    public function the_order_list_does_not_load_an_aggregate_per_row(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 40);
        $user = User::factory()->create();

        $this->placeOrder([$offer], $user->id);
        $one = $this->countQueries(fn () => $this->asUser($user)->get('/account/orders'));

        $this->placeOrder([$offer], $user->id);
        $this->placeOrder([$offer], $user->id);
        $three = $this->countQueries(fn () => $this->asUser($user)->get('/account/orders'));

        $this->assertSame($one, $three, "One order took {$one} queries, three took {$three}.");
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
}
