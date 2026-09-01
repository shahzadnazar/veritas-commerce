<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use App\Modules\Cart\Actions\AddOfferToCart;
use App\Modules\Cart\Actions\UpdateCartLine;
use App\Modules\Cart\Enums\CartIssueCode;
use App\Modules\Cart\Exceptions\CartOperationRefused;
use App\Modules\Cart\Models\Cart;
use App\Modules\Cart\Models\CartItem;
use App\Modules\Cart\Queries\BuildCartView;
use App\Modules\Cart\Support\LineIdentity;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Inventory\Actions\AdjustInventory;
use App\Modules\Inventory\Enums\InventoryMovementReason;
use App\Modules\Offers\Enums\OfferStatus;
use App\Modules\Offers\Models\Offer;
use App\Modules\Sellers\Enums\SellerStatus;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The cart's own rules, before any HTTP or UI.
 *
 * A cart records intent against a specific seller's offer, re-checks that
 * intent on every read, and never reserves anything.
 */
final class CartDomainTest extends TestCase
{
    use BuildsCommerceFixtures;
    use RefreshDatabase;

    #[Test]
    public function a_cart_line_points_at_a_seller_offer_not_a_product(): void
    {
        ['offer' => $offer, 'product' => $product] = $this->sellableOffer();
        $cart = $this->cart();

        $item = app(AddOfferToCart::class)($cart, $offer->public_id, 2);

        // §2: the customer is buying one seller's commercial offer. A cart
        // holding only a product id would have to guess whose at checkout.
        $this->assertSame($offer->id, $item->offer_id);
        $this->assertSame(2, $item->quantity);
        $this->assertSame($product->id, $offer->product_id);
    }

    #[Test]
    public function adding_the_same_offer_twice_combines_into_one_line(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $cart = $this->cart();

        app(AddOfferToCart::class)($cart, $offer->public_id, 1);
        app(AddOfferToCart::class)($cart, $offer->public_id, 2);

        // A double-click is one intention, not two lines.
        $this->assertSame(1, CartItem::query()->where('cart_id', $cart->id)->count());
        $this->assertSame(3, CartItem::query()->where('cart_id', $cart->id)->firstOrFail()->quantity);
    }

    #[Test]
    public function the_database_refuses_a_duplicate_line_identity(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $cart = $this->cart();

        app(AddOfferToCart::class)($cart, $offer->public_id, 1);

        $this->expectException(QueryException::class);

        // The merge rule is the domain's; this is the backstop under it.
        CartItem::query()->create([
            'cart_id' => $cart->id,
            'offer_id' => $offer->id,
            'line_identity' => LineIdentity::for($offer->id),
            'quantity' => 1,
            'unit_price_at_add_minor' => $offer->price_minor,
        ]);
    }

    #[Test]
    public function line_identity_leaves_room_for_future_customisation(): void
    {
        // §5's seam. Two engravings of the same mug are two lines; the
        // schema already supports it because uniqueness is on the identity.
        $plain = LineIdentity::for(7);
        $engraved = LineIdentity::for(7, null, ['engraving' => 'For Alex']);
        $other = LineIdentity::for(7, null, ['engraving' => 'For Sam']);

        $this->assertNotSame($plain, $engraved);
        $this->assertNotSame($engraved, $other);

        // And it is order-independent, so the same choices always collapse
        // into the same line however the array was built.
        $this->assertSame(
            LineIdentity::for(7, 3, ['size' => 'L', 'colour' => 'red']),
            LineIdentity::for(7, 3, ['colour' => 'red', 'size' => 'L']),
        );
    }

    #[Test]
    public function an_offer_from_a_suspended_seller_cannot_be_added(): void
    {
        ['offer' => $offer, 'seller' => $seller] = $this->sellableOffer();
        $seller->forceFill(['status' => SellerStatus::Suspended->value])->save();

        $this->expectException(CartOperationRefused::class);

        // The Add button would have been disabled; the request arriving
        // anyway must be refused by the same rule.
        app(AddOfferToCart::class)($this->cart(), $offer->public_id, 1);
    }

    #[Test]
    public function an_inactive_offer_cannot_be_added(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $offer->forceFill(['status' => OfferStatus::Suspended->value])->save();

        $this->expectException(CartOperationRefused::class);

        app(AddOfferToCart::class)($this->cart(), $offer->public_id, 1);
    }

    #[Test]
    public function an_offer_on_an_unpublished_product_cannot_be_added(): void
    {
        ['offer' => $offer, 'product' => $product] = $this->sellableOffer();
        $product->forceFill(['status' => ProductStatus::Suspended->value])->save();

        $this->expectException(CartOperationRefused::class);

        app(AddOfferToCart::class)($this->cart(), $offer->public_id, 1);
    }

    #[Test]
    public function a_closed_store_cannot_sell(): void
    {
        ['offer' => $offer, 'store' => $store] = $this->sellableOffer();
        $store->forceFill(['is_open' => false])->save();

        $this->expectException(CartOperationRefused::class);

        app(AddOfferToCart::class)($this->cart(), $offer->public_id, 1);
    }

    #[Test]
    public function a_made_up_offer_id_is_refused_rather_than_erroring(): void
    {
        $this->expectException(CartOperationRefused::class);
        $this->expectExceptionMessage('not available to buy');

        app(AddOfferToCart::class)($this->cart(), '01JJJJJJJJJJJJJJJJJJJJJJJJ', 1);
    }

    #[Test]
    public function more_than_the_available_stock_cannot_be_added(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 3);

        try {
            app(AddOfferToCart::class)($this->cart(), $offer->public_id, 4);
            $this->fail('Adding more than exists must be refused.');
        } catch (CartOperationRefused $refused) {
            $this->assertSame(CartIssueCode::QuantityReduced, $refused->issue);
            $this->assertSame(3, $refused->available);
        }
    }

    #[Test]
    public function combining_lines_cannot_exceed_available_stock(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 3);
        $cart = $this->cart();

        app(AddOfferToCart::class)($cart, $offer->public_id, 2);

        // Two more would be four in a cart with three in stock.
        $this->expectException(CartOperationRefused::class);
        app(AddOfferToCart::class)($cart, $offer->public_id, 2);
    }

    #[Test]
    public function adding_to_a_cart_reserves_nothing(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 5);

        app(AddOfferToCart::class)($this->cart(), $offer->public_id, 5);

        $balance = DB::table('inventory_balances')->where('offer_id', $offer->id)->first();

        /*
         * §7, and the reason abandoned carts do not strangle a
         * marketplace: intent is not a hold. Availability is unchanged, so
         * the next customer can still buy all five.
         */
        $this->assertNotNull($balance, 'Opening stock must have created a balance row to check.');
        $this->assertSame(0, (int) $balance->reserved);
        $this->assertSame(5, (int) $balance->available);
        $this->assertDatabaseCount('inventory_reservations', 0);
    }

    #[Test]
    public function quantity_can_be_updated_and_a_line_removed(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 10);
        $cart = $this->cart();

        $item = app(AddOfferToCart::class)($cart, $offer->public_id, 2);

        app(UpdateCartLine::class)->setQuantity($cart, $item->line_identity, 5);
        $this->assertSame(5, $item->refresh()->quantity);

        // Zero is how a stepper removes something.
        app(UpdateCartLine::class)->setQuantity($cart, $item->line_identity, 0);
        $this->assertDatabaseCount('cart_items', 0);
    }

    #[Test]
    public function a_quantity_beyond_stock_is_refused_on_update_too(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 4);
        $cart = $this->cart();
        $item = app(AddOfferToCart::class)($cart, $offer->public_id, 1);

        $this->expectException(CartOperationRefused::class);

        app(UpdateCartLine::class)->setQuantity($cart, $item->line_identity, 5);
    }

    #[Test]
    public function a_line_identity_from_another_cart_updates_nothing(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $mine = $this->cart();
        $theirs = $this->cart(sessionToken: 'someone-else');

        $theirItem = app(AddOfferToCart::class)($theirs, $offer->public_id, 1);

        // Scoped to the cart the caller resolved from the session, so
        // another customer's line simply is not there.
        $this->expectException(CartOperationRefused::class);
        app(UpdateCartLine::class)->setQuantity($mine, $theirItem->line_identity, 5);
    }

    #[Test]
    public function the_view_prices_from_the_live_offer_not_from_what_was_added(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 9_900);
        $cart = $this->cart();
        app(AddOfferToCart::class)($cart, $offer->public_id, 2);

        $offer->forceFill(['price_minor' => 12_500])->save();

        $view = app(BuildCartView::class)($cart);
        $line = $view->lines()[0];

        // §9: the cart is a current estimate, always.
        $this->assertSame(12_500, $line->unitPrice->minor);
        $this->assertSame(25_000, $line->lineTotal->minor);
        $this->assertSame(25_000, $view->subtotal->minor);
    }

    #[Test]
    public function a_price_change_is_reported_rather_than_hidden(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 9_900);
        $cart = $this->cart();
        app(AddOfferToCart::class)($cart, $offer->public_id, 1);

        $offer->forceFill(['price_minor' => 12_500])->save();

        $issues = app(BuildCartView::class)($cart)->allIssues();

        $this->assertCount(1, $issues);
        $this->assertSame(CartIssueCode::PriceChanged, $issues[0]->code);
        $this->assertSame(9_900, $issues[0]->previousMinor);
        $this->assertSame(12_500, $issues[0]->currentMinor);
        // §39: a price change is shown and acknowledged, not blocking.
        $this->assertFalse($issues[0]->isBlocking());
    }

    #[Test]
    public function a_suspended_seller_makes_the_line_unavailable_without_substituting_anyone(): void
    {
        ['offer' => $offer, 'seller' => $seller] = $this->sellableOffer();
        $cart = $this->cart();
        app(AddOfferToCart::class)($cart, $offer->public_id, 1);

        $seller->forceFill(['status' => SellerStatus::Suspended->value])->save();

        $view = app(BuildCartView::class)($cart);
        $issues = $view->allIssues();

        $this->assertSame(CartIssueCode::SellerUnavailable, $issues[0]->code);
        $this->assertTrue($view->hasBlockingIssues());
        // §8: never quietly swap in another seller's offer. The line stays,
        // marked, and the customer decides.
        $this->assertCount(1, $view->lines());
        $this->assertSame($offer->id, $view->lines()[0]->offerId);
    }

    #[Test]
    public function stock_running_out_under_a_cart_is_reported(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 5);
        $cart = $this->cart();
        app(AddOfferToCart::class)($cart, $offer->public_id, 4);

        app(AdjustInventory::class)($offer, -3, InventoryMovementReason::Damaged, 'seller', 1);

        $issues = app(BuildCartView::class)($cart)->allIssues();

        $this->assertSame(CartIssueCode::QuantityReduced, $issues[0]->code);
        $this->assertSame(2, $issues[0]->available);
        $this->assertTrue($issues[0]->isBlocking());
    }

    #[Test]
    public function selling_out_entirely_is_its_own_issue(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 2);
        $cart = $this->cart();
        app(AddOfferToCart::class)($cart, $offer->public_id, 2);

        app(AdjustInventory::class)($offer, -2, InventoryMovementReason::Damaged, 'seller', 1);

        $issues = app(BuildCartView::class)($cart)->allIssues();

        $this->assertSame(CartIssueCode::OutOfStock, $issues[0]->code);
    }

    #[Test]
    public function lines_are_grouped_by_seller_deterministically(): void
    {
        $cart = $this->cart();

        ['offer' => $first] = $this->sellableOffer('Kettle');
        ['offer' => $second] = $this->sellableOffer('Toaster');
        ['offer' => $third] = $this->sellableOffer('Grinder');

        // Added out of seller order on purpose.
        app(AddOfferToCart::class)($cart, $third->public_id, 1);
        app(AddOfferToCart::class)($cart, $first->public_id, 1);
        app(AddOfferToCart::class)($cart, $second->public_id, 1);

        $view = app(BuildCartView::class)($cart);
        $sellerIds = array_map(
            static fn ($group): int => $group->sellerAccountId,
            $view->groups,
        );

        $sorted = $sellerIds;
        sort($sorted);

        // The grouping the customer sees is the order the seller orders
        // will be numbered in, so it cannot depend on click order.
        $this->assertCount(3, $view->groups);
        $this->assertSame($sorted, $sellerIds);
    }

    #[Test]
    public function reading_a_cart_costs_the_same_whether_it_holds_one_line_or_eight(): void
    {
        $cart = $this->cart();
        ['offer' => $first] = $this->sellableOffer('Kettle 1');
        app(AddOfferToCart::class)($cart, $first->public_id, 1);

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(BuildCartView::class)($cart);
        $one = count(DB::getQueryLog());
        DB::disableQueryLog();

        foreach (range(2, 8) as $index) {
            ['offer' => $offer] = $this->sellableOffer("Kettle {$index}");
            app(AddOfferToCart::class)($cart, $offer->public_id, 1);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(BuildCartView::class)($cart);
        $eight = count(DB::getQueryLog());
        DB::disableQueryLog();

        // §65: no per-line lookup of the offer, the store, the product or
        // the inventory.
        $this->assertSame($one, $eight, "One line took {$one} queries, eight took {$eight} — an N+1.");
    }

    private function cart(?int $userId = null, string $sessionToken = 'test-session'): Cart
    {
        return Cart::query()->create([
            'user_id' => $userId,
            'session_token' => $userId === null ? $sessionToken : null,
            'status' => 'active',
            'last_activity_at' => now(),
        ]);
    }
}
