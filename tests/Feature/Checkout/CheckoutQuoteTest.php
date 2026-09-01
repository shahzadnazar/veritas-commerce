<?php

declare(strict_types=1);

namespace Tests\Feature\Checkout;

use App\Modules\Cart\Actions\AddOfferToCart;
use App\Modules\Cart\Enums\CartIssueCode;
use App\Modules\Cart\Models\CartItem;
use App\Modules\Cart\Support\LineIdentity;
use App\Modules\Checkout\Actions\QuoteCheckout;
use App\Modules\Inventory\Actions\AdjustInventory;
use App\Modules\Inventory\Enums\InventoryMovementReason;
use App\Modules\Offers\Enums\OfferStatus;
use App\Modules\Sellers\Enums\SellerStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsCommerceFixtures;
use Tests\TestCase;

/**
 * The quote is the server's number, and only the server's number.
 *
 * §11. Everything a customer could influence — the price they were shown,
 * the quantity they typed, the offer they clicked — is re-derived here
 * from the live rows before any money is committed to.
 */
final class CheckoutQuoteTest extends TestCase
{
    use BuildsCommerceFixtures;
    use RefreshDatabase;

    #[Test]
    public function the_quote_is_computed_from_the_live_offer_not_from_the_cart(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 5_000);
        $cart = $this->cart();

        app(AddOfferToCart::class)($cart, $offer->public_id, 2);

        // The seller reprices after the customer filled their basket.
        $offer->forceFill(['price_minor' => 6_000])->save();

        $quote = app(QuoteCheckout::class)($cart->refresh());

        // 2 x 6,000, not 2 x 5,000. The cart's price is display history.
        $this->assertSame(12_000, $quote->itemsTotal->minor);
        $this->assertSame(12_000, $quote->grandTotal->minor);
    }

    #[Test]
    public function a_price_change_is_reported_but_does_not_block_the_checkout(): void
    {
        ['offer' => $offer] = $this->sellableOffer(priceMinor: 5_000);
        $cart = $this->cart();

        app(AddOfferToCart::class)($cart, $offer->public_id, 1);
        $offer->forceFill(['price_minor' => 5_500])->save();

        $quote = app(QuoteCheckout::class)($cart->refresh());

        // The customer is told, and may still buy at the new price —
        // refusing outright would strand every cart a seller repriced.
        $this->assertSame(CartIssueCode::PriceChanged, $quote->issues[0]->code);
        $this->assertSame([], $quote->blockingIssues());
        $this->assertTrue($quote->isBuyable());
    }

    #[Test]
    public function a_suspended_seller_blocks_the_checkout(): void
    {
        ['offer' => $offer, 'seller' => $seller] = $this->sellableOffer();
        $cart = $this->cart();

        app(AddOfferToCart::class)($cart, $offer->public_id, 1);
        $seller->forceFill(['status' => SellerStatus::Suspended->value])->save();

        $quote = app(QuoteCheckout::class)($cart->refresh());

        $this->assertFalse($quote->isBuyable());
        $this->assertSame(CartIssueCode::SellerUnavailable, $quote->blockingIssues()[0]->code);
    }

    #[Test]
    public function an_archived_offer_blocks_the_checkout(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $cart = $this->cart();

        app(AddOfferToCart::class)($cart, $offer->public_id, 1);
        $offer->forceFill(['status' => OfferStatus::Archived->value])->save();

        $quote = app(QuoteCheckout::class)($cart->refresh());

        $this->assertFalse($quote->isBuyable());
        $this->assertSame(CartIssueCode::OfferUnavailable, $quote->blockingIssues()[0]->code);
    }

    #[Test]
    public function stock_that_ran_out_since_the_cart_page_blocks_the_checkout(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 5);
        $cart = $this->cart();

        app(AddOfferToCart::class)($cart, $offer->public_id, 4);

        // Another customer takes most of it.
        app(AdjustInventory::class)($offer, -3, InventoryMovementReason::Lost, 'seller', 1);

        $quote = app(QuoteCheckout::class)($cart->refresh());

        $this->assertFalse($quote->isBuyable());
        $this->assertSame(CartIssueCode::QuantityReduced, $quote->blockingIssues()[0]->code);
        $this->assertSame(2, $quote->blockingIssues()[0]->available);
    }

    #[Test]
    public function an_empty_cart_is_never_buyable(): void
    {
        $quote = app(QuoteCheckout::class)($this->cart());

        // Worth stating: an empty checkout producing a zero-total order
        // would pass every money assertion in the suite.
        $this->assertSame(0, $quote->grandTotal->minor);
        $this->assertFalse($quote->isBuyable());
    }

    #[Test]
    public function a_null_cart_quotes_to_nothing_rather_than_failing(): void
    {
        $quote = app(QuoteCheckout::class)(null);

        $this->assertSame(0, $quote->itemsTotal->minor);
        $this->assertFalse($quote->isBuyable());
    }

    #[Test]
    public function shipping_is_charged_once_per_seller_order(): void
    {
        config()->set('veritas.checkout.shipping_per_seller_order_minor', 499);

        ['offer' => $a] = $this->sellableOffer('Kettle');
        ['offer' => $b] = $this->sellableOffer('Lamp');
        $cart = $this->cart();

        app(AddOfferToCart::class)($cart, $a->public_id, 1);
        app(AddOfferToCart::class)($cart, $b->public_id, 1);

        $quote = app(QuoteCheckout::class)($cart->refresh());

        // Two sellers are two parcels. One shipping fee across both would
        // be the platform quietly paying for the second.
        $this->assertSame(998, $quote->shippingTotal->minor);
        $this->assertSame(
            $quote->itemsTotal->minor + 998,
            $quote->grandTotal->minor,
        );
    }

    #[Test]
    public function two_lines_from_one_seller_are_one_shipping_charge(): void
    {
        config()->set('veritas.checkout.shipping_per_seller_order_minor', 499);

        ['offer' => $a, 'seller' => $seller, 'store' => $store] = $this->sellableOffer('Kettle');
        ['offer' => $b] = $this->sellableOffer('Lamp', seller: $seller, store: $store);
        $cart = $this->cart();

        app(AddOfferToCart::class)($cart, $a->public_id, 1);
        app(AddOfferToCart::class)($cart, $b->public_id, 1);

        $this->assertSame(499, app(QuoteCheckout::class)($cart->refresh())->shippingTotal->minor);
    }

    #[Test]
    public function the_grand_total_is_the_sum_of_its_parts_exactly(): void
    {
        config()->set('veritas.checkout.shipping_per_seller_order_minor', 350);

        ['offer' => $offer] = $this->sellableOffer(priceMinor: 3_333);
        $cart = $this->cart();

        app(AddOfferToCart::class)($cart, $offer->public_id, 3);

        $quote = app(QuoteCheckout::class)($cart->refresh());

        // Integer minor units throughout: no float ever touches money, so
        // the parts add up to the whole with no rounding to explain.
        $this->assertSame(9_999, $quote->itemsTotal->minor);
        $this->assertSame(
            $quote->itemsTotal->minor + $quote->shippingTotal->minor + $quote->taxTotal->minor,
            $quote->grandTotal->minor,
        );
    }

    #[Test]
    public function a_quantity_beyond_stock_written_straight_into_the_cart_is_still_caught(): void
    {
        ['offer' => $offer] = $this->sellableOffer(stock: 2);
        $cart = $this->cart();

        // The action would refuse this; a crafted request that bypassed it
        // must still not reach an order.
        CartItem::query()->create([
            'cart_id' => $cart->id,
            'offer_id' => $offer->id,
            'line_identity' => LineIdentity::for($offer->id),
            'quantity' => 50,
            'unit_price_at_add_minor' => $offer->price_minor,
        ]);

        $this->assertFalse(app(QuoteCheckout::class)($cart->refresh())->isBuyable());
    }
}
