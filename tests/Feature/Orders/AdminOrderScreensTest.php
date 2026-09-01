<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Modules\Commission\Models\CommissionRule;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Actions\CancelUnpaidOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsCommerceFixtures;
use Tests\TestCase;

/**
 * The platform's own view of an order.
 *
 * The one screen that legitimately reads across every seller, and the one
 * place where "staff" is not a single permission: support answers a
 * delivery question, finance sees what the platform took, and the role
 * matrix — not a boolean — decides which.
 */
final class AdminOrderScreensTest extends TestCase
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
    public function the_list_shows_orders_with_a_counted_seller_split(): void
    {
        ['offer' => $a] = $this->sellableOffer('Kettle');
        ['offer' => $b] = $this->sellableOffer('Lamp');

        $order = $this->placeOrder([$a, $b]);

        $this->asAdmin($this->makeAdmin())->get('/admin/orders')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Orders/Index')
                ->has('orders.data', 1)
                ->where('orders.data.0.reference', $order->reference)
                ->where('orders.data.0.sellerOrderCount', 2));
    }

    #[Test]
    public function the_list_filters_on_the_server(): void
    {
        ['offer' => $a, 'store' => $storeA] = $this->sellableOffer('Kettle');
        ['offer' => $b] = $this->sellableOffer('Lamp');

        $first = $this->placeOrder([$a]);
        $second = $this->placeOrder([$b]);

        $admin = $this->makeAdmin();

        $this->asAdmin($admin)->get('/admin/orders?reference='.$first->reference)
            ->assertInertia(fn ($page) => $page->has('orders.data', 1)
                ->where('orders.data.0.reference', $first->reference));

        $this->asAdmin($admin)->get('/admin/orders?seller='.urlencode($storeA->name))
            ->assertInertia(fn ($page) => $page->has('orders.data', 1));

        $this->asAdmin($admin)->get('/admin/orders?status=cancelled')
            ->assertInertia(fn ($page) => $page->has('orders.data', 0));

        app(CancelUnpaidOrder::class)($second);

        $this->asAdmin($admin)->get('/admin/orders?status=cancelled')
            ->assertInertia(fn ($page) => $page->has('orders.data', 1));
    }

    #[Test]
    public function the_detail_page_shows_the_whole_hierarchy(): void
    {
        ['offer' => $a] = $this->sellableOffer('Kettle', priceMinor: 4_000);
        ['offer' => $b] = $this->sellableOffer('Lamp', priceMinor: 6_000);

        $order = $this->placeOrder([$a, $b]);

        $this->asAdmin($this->makeAdmin())->get('/admin/orders/'.$order->reference)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Orders/Show')
                ->where('order.reference', $order->reference)
                ->where('order.grandTotal.minor', 10_000)
                ->has('order.sellerOrders', 2)
                ->where('order.sellerOrders.0.reference', $order->reference.'-01')
                ->where('order.sellerOrders.1.reference', $order->reference.'-02')
                ->has('order.sellerOrders.0.items', 1));
    }

    #[Test]
    public function the_detail_page_shows_the_checkout_holds_and_history(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $order = $this->placeOrder([$offer]);

        $this->asAdmin($this->makeAdmin())->get('/admin/orders/'.$order->reference)
            ->assertInertia(fn ($page) => $page
                ->where('checkout.status', 'completed')
                ->where('checkout.reservationReference', $order->reservation_reference)
                ->has('reservations', 1)
                ->where('reservations.0.status', 'held')
                // Parent and child transitions both, so a dispute can be
                // reconstructed from one screen.
                ->has('history', 2)
                ->where('history.0.to', 'pending_payment'));
    }

    #[Test]
    public function finance_columns_need_the_sensitive_permission(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 10_000);
        $order = $this->placeOrder([$offer]);

        // Finance sees the split.
        $this->asAdmin($this->makeAdmin(AdminRole::FinanceAdmin))
            ->get('/admin/orders/'.$order->reference)
            ->assertInertia(fn ($page) => $page
                ->where('canSeeFinance', true)
                ->where('order.sellerOrders.0.commissionTotal.minor', 1_200)
                ->where('order.sellerOrders.0.sellerEarningTotal.minor', 8_800)
                ->where('order.sellerOrders.0.items.0.commissionRate', '12.00'));
    }

    #[Test]
    public function support_can_read_an_order_without_seeing_the_commission(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $order = $this->placeOrder([$offer]);

        // The separation §18 asks for: an operational role reads the
        // order, and the finance fields are simply not in the payload.
        $this->asAdmin($this->makeAdmin(AdminRole::Support))
            ->get('/admin/orders/'.$order->reference)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('canSeeFinance', false)
                ->missing('order.sellerOrders.0.commissionTotal')
                ->missing('order.sellerOrders.0.sellerEarningTotal')
                ->missing('order.sellerOrders.0.items.0.commission')
                ->missing('order.sellerOrders.0.items.0.commissionRate'));
    }

    #[Test]
    public function an_analyst_reads_orders_but_not_their_finance(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $order = $this->placeOrder([$offer]);

        $this->asAdmin($this->makeAdmin(AdminRole::Analyst))
            ->get('/admin/orders/'.$order->reference)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('canSeeFinance', false));
    }

    #[Test]
    public function a_role_without_orders_view_is_refused_outright(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $order = $this->placeOrder([$offer]);

        // Catalogue moderation is a different job. Not is_admin.
        $this->asAdmin($this->makeAdmin(AdminRole::CatalogModerator))
            ->get('/admin/orders')->assertForbidden();

        $this->asAdmin($this->makeAdmin(AdminRole::CatalogModerator))
            ->get('/admin/orders/'.$order->reference)->assertForbidden();
    }

    #[Test]
    public function a_customer_session_cannot_reach_the_admin_order_screens(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $user = User::factory()->create();
        $order = $this->placeOrder([$offer], $user->id);

        // Separate guard, separate session: a customer login is not a
        // step toward an admin one.
        $this->asUser($user)->get('/admin/orders')->assertRedirect('/admin/login');
        $this->asUser($user)->get('/admin/orders/'.$order->reference)->assertRedirect('/admin/login');
    }

    #[Test]
    public function a_seller_session_cannot_reach_the_admin_order_screens(): void
    {
        ['seller' => $seller, 'store' => $store, 'user' => $sellerUser] = $this->makeSeller();
        ['offer' => $offer] = $this->sellableOffer('Kettle', seller: $seller, store: $store);
        $order = $this->placeOrder([$offer]);

        $this->asUser($sellerUser)->get('/admin/orders/'.$order->reference)
            ->assertRedirect('/admin/login');
    }

    #[Test]
    public function the_admin_order_screens_are_never_indexable(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $order = $this->placeOrder([$offer]);
        $admin = $this->makeAdmin();

        $this->asAdmin($admin)->get('/admin/orders')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->asAdmin($admin)->get('/admin/orders/'.$order->reference)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    #[Test]
    public function the_admin_screens_cost_a_fixed_number_of_queries(): void
    {
        ['offer' => $a] = $this->sellableOffer('Kettle');
        ['offer' => $b] = $this->sellableOffer('Lamp');
        ['offer' => $c] = $this->sellableOffer('Rug');
        $admin = $this->makeAdmin();

        $small = $this->placeOrder([$a]);
        $one = $this->countQueries(fn () => $this->asAdmin($admin)->get('/admin/orders'));

        $this->placeOrder([$b]);
        $this->placeOrder([$c]);
        $three = $this->countQueries(fn () => $this->asAdmin($admin)->get('/admin/orders'));

        // The seller-order count is a SQL count, not a loaded aggregate.
        $this->assertSame($one, $three, "One row took {$one} queries, three took {$three}.");

        $detailOne = $this->countQueries(
            fn () => $this->asAdmin($admin)->get('/admin/orders/'.$small->reference),
        );
        $big = $this->placeOrder([$a, $b, $c]);
        $detailThree = $this->countQueries(
            fn () => $this->asAdmin($admin)->get('/admin/orders/'.$big->reference),
        );

        $this->assertSame($detailOne, $detailThree);
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
