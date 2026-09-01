<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use App\Modules\Cart\Actions\AddOfferToCart;
use App\Modules\Cart\Models\CartItem;
use App\Modules\Cart\Support\LineIdentity;
use App\Modules\Identity\Models\User;
use App\Modules\Sellers\Enums\SellerStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The basket over HTTP.
 *
 * Ownership comes from the session on every route, so these tests drive
 * the real endpoints rather than the actions: the point of the HTTP layer
 * is that there is no cart id anywhere in it, and only a request can prove
 * that.
 */
final class CartHttpTest extends TestCase
{
    use BuildsCommerceFixtures;
    use RefreshDatabase;

    #[Test]
    public function the_cart_page_renders_the_server_built_view(): void
    {
        ['offer' => $offer, 'product' => $product] = $this->sellableOffer(priceMinor: 4_500);

        $this->post('/cart', ['offer' => $offer->public_id, 'quantity' => 2]);

        $this->get('/cart')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Cart/Index')
                ->where('cartView.itemCount', 1)
                ->where('cartView.quantityCount', 2)
                ->where('cartView.subtotalMinor', 9_000)
                ->where('cartView.groups.0.lines.0.productTitle', $product->title)
                ->where('cartView.groups.0.lines.0.quantity', 2));
    }

    #[Test]
    public function an_empty_basket_renders_rather_than_erroring(): void
    {
        $this->get('/cart')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('cartView.itemCount', 0));
    }

    #[Test]
    public function adding_an_offer_creates_a_cart_bound_to_the_session(): void
    {
        ['offer' => $offer] = $this->sellableOffer();

        $this->from('/cart')
            ->post('/cart', ['offer' => $offer->public_id, 'quantity' => 1])
            ->assertRedirect('/cart');

        $this->assertDatabaseCount('carts', 1);
        $this->assertSame(1, CartItem::query()->count());
        // Session-owned, not user-owned: an anonymous shopper gets a cart.
        $this->assertNull(DB::table('carts')->value('user_id'));
        $this->assertNotNull(DB::table('carts')->value('session_token'));
    }

    #[Test]
    public function a_suspended_sellers_offer_is_refused_with_a_message_not_a_code(): void
    {
        ['offer' => $offer, 'seller' => $seller] = $this->sellableOffer();
        $seller->forceFill(['status' => SellerStatus::Suspended->value])->save();

        $response = $this->from('/cart')->post('/cart', ['offer' => $offer->public_id]);

        $response->assertSessionHasErrors('offer');
        $this->assertDatabaseCount('cart_items', 0);

        $message = (string) session('errors')?->first('offer');
        $this->assertStringNotContainsString('OFFER_UNAVAILABLE', $message);
        $this->assertStringNotContainsString('SELLER_UNAVAILABLE', $message);
    }

    #[Test]
    public function a_made_up_offer_id_is_refused(): void
    {
        $this->from('/cart')
            ->post('/cart', ['offer' => '01ARZ3NDEKTSV4RRFFQ69G5FAV'])
            ->assertSessionHasErrors('offer');

        $this->assertDatabaseCount('cart_items', 0);
    }

    #[Test]
    public function a_quantity_beyond_stock_is_refused_and_names_what_is_left(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 3);

        $this->from('/cart')->post('/cart', ['offer' => $offer->public_id, 'quantity' => 5])
            ->assertSessionHasErrors('offer');

        $this->assertStringContainsString('Only 3', (string) session('errors')?->first('offer'));
    }

    #[Test]
    public function a_quantity_update_goes_through_the_server_and_is_capped_by_stock(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 4);
        $this->post('/cart', ['offer' => $offer->public_id, 'quantity' => 1]);

        $line = LineIdentity::for($offer->id);

        $this->from('/cart')->patch('/cart/'.$line, ['quantity' => 3])->assertRedirect('/cart');
        $this->assertSame(3, (int) CartItem::query()->value('quantity'));

        // The backend wins: a number beyond stock is refused, not clamped
        // silently, so the customer is told what happened.
        $this->from('/cart')->patch('/cart/'.$line, ['quantity' => 9])
            ->assertSessionHasErrors('quantity');
        $this->assertSame(3, (int) CartItem::query()->value('quantity'));
    }

    #[Test]
    public function setting_a_quantity_to_zero_removes_the_line(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $this->post('/cart', ['offer' => $offer->public_id, 'quantity' => 2]);

        $this->patch('/cart/'.LineIdentity::for($offer->id), ['quantity' => 0]);

        $this->assertSame(0, CartItem::query()->count());
    }

    #[Test]
    public function a_line_can_be_removed(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $this->post('/cart', ['offer' => $offer->public_id]);

        $this->from('/cart')->delete('/cart/'.LineIdentity::for($offer->id))->assertRedirect('/cart');

        $this->assertSame(0, CartItem::query()->count());
    }

    #[Test]
    public function a_line_identity_from_another_session_cannot_be_touched(): void
    {
        ['offer' => $offer] = $this->sellableOffer();

        $theirs = $this->cart(sessionToken: 'someone-else');
        app(AddOfferToCart::class)($theirs, $offer->public_id, 2);

        // This browser has no cart at all, so the same identity resolves
        // to nothing rather than to their line.
        $this->patch('/cart/'.LineIdentity::for($offer->id), ['quantity' => 1]);
        $this->delete('/cart/'.LineIdentity::for($offer->id));

        $this->assertSame(2, (int) CartItem::query()->where('cart_id', $theirs->id)->value('quantity'));
    }

    #[Test]
    public function the_header_count_comes_from_the_database(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 10);

        $this->get('/cart')->assertInertia(fn ($page) => $page->where('cart.count', 0));

        $this->post('/cart', ['offer' => $offer->public_id, 'quantity' => 3]);

        $this->get('/cart')->assertInertia(fn ($page) => $page->where('cart.count', 3));
    }

    #[Test]
    public function the_header_count_follows_the_signed_in_customers_own_cart(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $user = User::factory()->create();

        $mine = $this->cart(userId: $user->id);
        app(AddOfferToCart::class)($mine, $offer->public_id, 4);

        $this->actingAs($user)->get('/cart')
            ->assertInertia(fn ($page) => $page->where('cart.count', 4));
    }

    #[Test]
    public function the_cart_page_reports_a_price_change_without_blocking(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 5_000);
        $this->post('/cart', ['offer' => $offer->public_id]);

        $offer->forceFill(['price_minor' => 6_000])->save();

        $this->get('/cart')->assertInertia(fn ($page) => $page
            ->where('cartView.hasBlockingIssues', false)
            ->where('cartView.groups.0.lines.0.issues.0.code', 'PRICE_CHANGED')
            ->where('cartView.groups.0.lines.0.unitPriceMinor', 6_000));
    }

    #[Test]
    public function the_cart_page_blocks_when_a_seller_stops_trading(): void
    {
        ['offer' => $offer, 'seller' => $seller] = $this->sellableOffer();
        $this->post('/cart', ['offer' => $offer->public_id]);

        $seller->forceFill(['status' => SellerStatus::Suspended->value])->save();

        $this->get('/cart')->assertInertia(fn ($page) => $page
            ->where('cartView.hasBlockingIssues', true)
            ->where('cartView.groups.0.lines.0.issues.0.code', 'SELLER_UNAVAILABLE'));
    }

    #[Test]
    public function the_line_carries_the_ceiling_the_quantity_control_needs(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 6);
        $this->post('/cart', ['offer' => $offer->public_id]);

        // The lower of what the seller has and the per-line cap — so the
        // control cannot offer a number the server would refuse.
        $this->get('/cart')->assertInertia(fn ($page) => $page
            ->where('cartView.groups.0.lines.0.maxQuantity', 6)
            ->where('maxLineQuantity', AddOfferToCart::MAX_LINE_QUANTITY));
    }

    #[Test]
    public function the_cart_and_checkout_pages_are_never_indexable(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $this->post('/cart', ['offer' => $offer->public_id]);

        // A header rather than only a meta tag: it reaches a crawler even
        // if SSR is misconfigured, which is exactly when an accidental
        // index of somebody's basket would happen.
        $this->get('/cart')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get('/checkout')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    #[Test]
    public function the_cart_page_costs_a_fixed_number_of_queries(): void
    {
        ['offer' => $a] = $this->sellableOffer('Kettle');
        ['offer' => $b] = $this->sellableOffer('Lamp');
        ['offer' => $c] = $this->sellableOffer('Rug');

        $this->post('/cart', ['offer' => $a->public_id]);
        $one = $this->countQueries(fn () => $this->get('/cart'));

        $this->post('/cart', ['offer' => $b->public_id]);
        $this->post('/cart', ['offer' => $c->public_id]);
        $three = $this->countQueries(fn () => $this->get('/cart'));

        // Three lines from three sellers must not cost three times one.
        $this->assertSame($one, $three, "One line took {$one} queries, three took {$three}.");
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
