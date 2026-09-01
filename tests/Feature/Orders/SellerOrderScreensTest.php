<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Modules\Commission\Models\CommissionRule;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Actions\MarkOrderPaid;
use App\Modules\Orders\Models\SellerOrder;
use App\Modules\Sellers\Enums\SellerRole;
use App\Modules\Sellers\Models\SellerMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsCommerceFixtures;
use Tests\TestCase;

/**
 * A seller's order screens, and the wall around them.
 *
 * The interesting assertions are the negative ones. Two sellers on one
 * customer order is the case a marketplace has to get right, and every
 * test here that manipulates a reference is asking the same question: can
 * seller A reach any part of seller B's half?
 */
final class SellerOrderScreensTest extends TestCase
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
    public function a_seller_sees_their_own_orders(): void
    {
        ['seller' => $seller, 'store' => $store, 'user' => $user] = $this->makeSeller();
        ['offer' => $offer] = $this->sellableOffer('Kettle', seller: $seller, store: $store);

        $order = $this->placeOrder([$offer]);

        $this->asUser($user)->get('/seller/orders')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Orders/Index')
                ->has('orders.data', 1)
                ->where('orders.data.0.reference', $order->reference.'-01')
                ->where('orders.data.0.parentReference', $order->reference));
    }

    #[Test]
    public function a_seller_never_sees_another_sellers_half_of_the_same_order(): void
    {
        ['seller' => $sellerA, 'store' => $storeA, 'user' => $userA] = $this->makeSeller();
        ['seller' => $sellerB, 'store' => $storeB] = $this->makeSeller();

        ['offer' => $offerA] = $this->sellableOffer('Kettle', seller: $sellerA, store: $storeA);
        ['offer' => $offerB] = $this->sellableOffer('Lamp', seller: $sellerB, store: $storeB);

        $order = $this->placeOrder([$offerA, $offerB]);

        // One customer order, two seller orders. A sees exactly one row.
        $this->asUser($userA)->get('/seller/orders')
            ->assertInertia(fn ($page) => $page->has('orders.data', 1));

        $this->assertSame(2, SellerOrder::query()->withoutGlobalScopes()->count());
    }

    #[Test]
    public function a_seller_cannot_open_another_sellers_order_by_reference(): void
    {
        ['seller' => $sellerA, 'store' => $storeA, 'user' => $userA] = $this->makeSeller();
        ['seller' => $sellerB, 'store' => $storeB] = $this->makeSeller();

        ['offer' => $offerA] = $this->sellableOffer('Kettle', seller: $sellerA, store: $storeA);
        ['offer' => $offerB] = $this->sellableOffer('Lamp', seller: $sellerB, store: $storeB);

        $order = $this->placeOrder([$offerA, $offerB]);

        $theirs = SellerOrder::query()->withoutGlobalScopes()
            ->where('marketplace_order_id', $order->id)
            ->where('seller_account_id', $sellerB->id)
            ->firstOrFail();

        // Hand-typed, not navigated to. A 404 rather than a 403, so the
        // reference does not confirm that the order exists.
        $this->asUser($userA)->get('/seller/orders/'.$theirs->reference)->assertNotFound();
    }

    #[Test]
    public function the_parent_reference_does_not_open_the_whole_order(): void
    {
        ['seller' => $seller, 'store' => $store, 'user' => $user] = $this->makeSeller();
        ['offer' => $offer] = $this->sellableOffer('Kettle', seller: $seller, store: $store);

        $order = $this->placeOrder([$offer]);

        // The parent number is shown on the page because customers quote
        // it. It is not a route into the parent.
        $this->asUser($user)->get('/seller/orders/'.$order->reference)->assertNotFound();
    }

    #[Test]
    public function filtering_by_another_sellers_parent_reference_returns_nothing(): void
    {
        ['seller' => $sellerA, 'store' => $storeA, 'user' => $userA] = $this->makeSeller();
        ['seller' => $sellerB, 'store' => $storeB] = $this->makeSeller();

        ['offer' => $offerA] = $this->sellableOffer('Kettle', seller: $sellerA, store: $storeA);
        ['offer' => $offerB] = $this->sellableOffer('Lamp', seller: $sellerB, store: $storeB);

        $mine = $this->placeOrder([$offerA]);
        $theirs = $this->placeOrder([$offerB]);

        // Search is not a side channel: filtering by a parent reference
        // this seller has no part in returns an empty page, not a hit.
        $this->asUser($userA)->get('/seller/orders?parent='.$theirs->reference)
            ->assertInertia(fn ($page) => $page->has('orders.data', 0));

        $this->asUser($userA)->get('/seller/orders?parent='.$mine->reference)
            ->assertInertia(fn ($page) => $page->has('orders.data', 1));
    }

    #[Test]
    public function searching_for_another_sellers_order_number_returns_nothing(): void
    {
        ['seller' => $sellerA, 'store' => $storeA, 'user' => $userA] = $this->makeSeller();
        ['seller' => $sellerB, 'store' => $storeB] = $this->makeSeller();

        ['offer' => $offerA] = $this->sellableOffer('Kettle', seller: $sellerA, store: $storeA);
        ['offer' => $offerB] = $this->sellableOffer('Lamp', seller: $sellerB, store: $storeB);

        $this->placeOrder([$offerA]);
        $order = $this->placeOrder([$offerB]);

        $this->asUser($userA)->get('/seller/orders?reference='.$order->reference.'-01')
            ->assertInertia(fn ($page) => $page->has('orders.data', 0));
    }

    #[Test]
    public function the_detail_page_shows_only_this_sellers_items(): void
    {
        ['seller' => $sellerA, 'store' => $storeA, 'user' => $userA] = $this->makeSeller();
        ['seller' => $sellerB, 'store' => $storeB] = $this->makeSeller();

        ['offer' => $offerA] = $this->sellableOffer('Kettle', priceMinor: 4_000, seller: $sellerA, store: $storeA);
        ['offer' => $offerB] = $this->sellableOffer('Lamp', priceMinor: 6_000, seller: $sellerB, store: $storeB);

        $order = $this->placeOrder([$offerA, $offerB]);
        $mine = SellerOrder::query()->withoutGlobalScopes()
            ->where('marketplace_order_id', $order->id)
            ->where('seller_account_id', $sellerA->id)
            ->firstOrFail();

        $this->asUser($userA)->get('/seller/orders/'.$mine->reference)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Orders/Show')
                ->has('sellerOrder.items', 1)
                ->where('sellerOrder.items.0.productTitle', 'Kettle')
                ->where('sellerOrder.itemsTotal.minor', 4_000)
                ->where('parent.reference', $order->reference)
                // The customer's address, because a parcel needs one.
                ->where('parent.shippingAddress.line1', '12 Analytical Way')
                // And nothing at all about the parent's money or its
                // other sellers.
                ->missing('parent.grandTotal')
                ->missing('order'));
    }

    #[Test]
    public function an_unpaid_seller_order_is_visible_but_not_actionable(): void
    {
        ['seller' => $seller, 'store' => $store, 'user' => $user] = $this->makeSeller();
        ['offer' => $offer] = $this->sellableOffer('Kettle', seller: $seller, store: $store);

        $order = $this->placeOrder([$offer]);

        // §14, asserted server-side even though no fulfilment buttons
        // exist yet: the flag the UI reads must already be false, so the
        // milestone that adds those buttons cannot add them unguarded.
        $this->asUser($user)->get('/seller/orders/'.$order->reference.'-01')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('sellerOrder.status', 'pending_payment')
                ->where('fulfilment.actionable', false)
                ->where(
                    'fulfilment.reason',
                    'This order cannot be packed or shipped until payment is confirmed.',
                ));
    }

    #[Test]
    public function a_paid_seller_order_becomes_actionable(): void
    {
        ['seller' => $seller, 'store' => $store, 'user' => $user] = $this->makeSeller();
        ['offer' => $offer] = $this->sellableOffer('Kettle', seller: $seller, store: $store);

        $order = $this->placeOrder([$offer]);
        app(MarkOrderPaid::class)($order);

        $this->asUser($user)->get('/seller/orders/'.$order->reference.'-01')
            ->assertInertia(fn ($page) => $page
                ->where('sellerOrder.status', 'paid')
                ->where('fulfilment.actionable', true));
    }

    #[Test]
    public function commission_is_shown_only_to_a_role_that_may_see_finance(): void
    {
        ['seller' => $seller, 'store' => $store, 'user' => $owner] = $this->makeSeller();
        ['offer' => $offer] = $this->sellableOffer('Kettle', seller: $seller, store: $store);

        $order = $this->placeOrder([$offer]);

        $this->asUser($owner)->get('/seller/orders/'.$order->reference.'-01')
            ->assertInertia(fn ($page) => $page
                ->where('canSeeFinance', true)
                ->has('sellerOrder.commissionTotal')
                ->has('sellerOrder.sellerEarningTotal'));

        // A warehouse account packs boxes and has no reason to see what
        // the platform took.
        ['user' => $packer] = $this->makeSellerMember($seller->id, SellerRole::FulfillmentManager);

        $this->asUser($packer)->get('/seller/orders/'.$order->reference.'-01')
            ->assertInertia(fn ($page) => $page
                ->where('canSeeFinance', false)
                ->missing('sellerOrder.commissionTotal')
                ->missing('sellerOrder.items.0.commission'));
    }

    #[Test]
    public function a_role_without_the_orders_permission_is_refused(): void
    {
        ['seller' => $seller, 'store' => $store] = $this->makeSeller();
        ['offer' => $offer] = $this->sellableOffer('Kettle', seller: $seller, store: $store);
        $this->placeOrder([$offer]);

        ['user' => $catalogueOnly] = $this->makeSellerMember($seller->id, SellerRole::CatalogManager);

        $this->asUser($catalogueOnly)->get('/seller/orders')->assertForbidden();
    }

    #[Test]
    public function a_customer_with_no_seller_membership_cannot_reach_the_portal(): void
    {
        ['seller' => $seller, 'store' => $store] = $this->makeSeller();
        ['offer' => $offer] = $this->sellableOffer('Kettle', seller: $seller, store: $store);
        $order = $this->placeOrder([$offer]);

        $shopper = User::factory()->create();

        // 404, matching the portal's established policy: a shopper who
        // is not a member learns nothing about whether the portal, or
        // that order, exists at all.
        $this->asUser($shopper)->get('/seller/orders')->assertNotFound();
        $this->asUser($shopper)->get('/seller/orders/'.$order->reference.'-01')->assertNotFound();
    }

    #[Test]
    public function the_seller_order_screens_are_never_indexable(): void
    {
        ['seller' => $seller, 'store' => $store, 'user' => $user] = $this->makeSeller();
        ['offer' => $offer] = $this->sellableOffer('Kettle', seller: $seller, store: $store);
        $order = $this->placeOrder([$offer]);

        $this->asUser($user)->get('/seller/orders')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->asUser($user)->get('/seller/orders/'.$order->reference.'-01')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    #[Test]
    public function the_seller_screens_cost_a_fixed_number_of_queries(): void
    {
        ['seller' => $seller, 'store' => $store, 'user' => $user] = $this->makeSeller();
        ['offer' => $a] = $this->sellableOffer('Kettle', seller: $seller, store: $store);
        ['offer' => $b] = $this->sellableOffer('Lamp', seller: $seller, store: $store);
        ['offer' => $c] = $this->sellableOffer('Rug', seller: $seller, store: $store);

        $small = $this->placeOrder([$a]);
        $one = $this->countQueries(fn () => $this->asUser($user)->get('/seller/orders'));

        $this->placeOrder([$b]);
        $this->placeOrder([$c]);
        $three = $this->countQueries(fn () => $this->asUser($user)->get('/seller/orders'));

        $this->assertSame($one, $three, "One order took {$one} queries, three took {$three}.");

        $detailOne = $this->countQueries(
            fn () => $this->asUser($user)->get('/seller/orders/'.$small->reference.'-01'),
        );
        $big = $this->placeOrder([$a, $b, $c]);
        $detailThree = $this->countQueries(
            fn () => $this->asUser($user)->get('/seller/orders/'.$big->reference.'-01'),
        );

        $this->assertSame($detailOne, $detailThree);
    }

    /** @return array{user: User} */
    private function makeSellerMember(int $sellerId, SellerRole $role): array
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
}
