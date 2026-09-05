<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Modules\Cart\Actions\AddOfferToCart;
use App\Modules\Cart\Models\Cart;
use App\Modules\Cart\Models\CartItem;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Models\WishlistItem;
use App\Modules\Identity\Models\CustomerAddress;
use App\Modules\Identity\Models\User;
use App\Modules\Offers\Models\Offer;
use App\Modules\Orders\Models\Shipment;
use App\Modules\Payouts\Models\PayoutAccount;
use App\Modules\Payouts\Models\PayoutRequest;
use App\Modules\Reviews\Models\ProductReview;
use App\Modules\Sellers\Enums\SellerRole;
use App\Modules\Sellers\Enums\SellerStatus;
use App\Modules\Sellers\Models\SellerAccount;
use App\Modules\Sellers\Models\SellerApplication;
use App\Modules\Sellers\Models\SellerApplicationDocument;
use App\Modules\Sellers\Models\SellerMembership;
use App\Modules\Stores\Models\Store;
use Illuminate\Support\Str;

/**
 * Two complete marketplaces that have never heard of each other.
 *
 * The IDOR matrix needs more than two rows in a table. It needs two
 * tenants that each own the full set of things a real one owns — a store,
 * a product, an offer with stock, a customer, a cart, a paid order, a
 * shipment, a payout request, a private document, a review — so that
 * every identifier the attacker can name is an identifier that really
 * resolves to somebody.
 *
 * That matters more than it sounds. A test where the foreign id does not
 * exist proves only that the application 404s on nonsense. The whole
 * question is what happens when the id is real, valid and someone else's.
 *
 * Built through the application's own actions wherever a state machine is
 * involved, so a fixture cannot manufacture a state the marketplace could
 * not reach on its own.
 */
trait BuildsTenantScenarios
{
    /**
     * One tenant, entire.
     *
     * @return array<string, mixed>
     */
    protected function tenantWorld(string $label): array
    {
        $seller = SellerAccount::factory()->create([
            'status' => SellerStatus::Approved->value,
            'legal_name' => "{$label} Trading Ltd",
        ]);

        $store = Store::factory()->create([
            'seller_account_id' => $seller->id,
            'slug' => Str::slug("{$label}-store"),
            'is_open' => true,
        ]);

        $sellerUser = User::factory()->create(['email' => "seller-{$label}@example.test"]);

        $membership = SellerMembership::factory()->create([
            'seller_account_id' => $seller->id,
            'user_id' => $sellerUser->id,
            'role' => SellerRole::Owner->value,
        ]);

        ['offer' => $offer, 'product' => $product] = $this->sellableOffer(
            title: "{$label} Cordless Kettle",
            priceMinor: 10_000,
            stock: 20,
            seller: $seller,
            store: $store,
        );

        // A product this seller proposed and may still edit — separate from
        // the published one above, because a proposal is only editable
        // while it is pending, and the catalogue is shared.
        $proposal = Product::factory()->proposedBy($seller->id)->create([
            'title' => "{$label} Proposed Desk Lamp",
            // Changes-requested rather than pending: a proposal is only
            // editable by the seller who made it while a moderator has
            // handed it back, and an edit route nobody may use would make
            // the matrix's owner control pass for the wrong reason.
            'status' => ProductStatus::ChangesRequested->value,
            'published_at' => null,
        ]);

        $customer = User::factory()->create(['email' => "customer-{$label}@example.test"]);

        $address = CustomerAddress::factory()->create([
            'user_id' => $customer->id,
            'label' => "{$label} home",
        ]);

        $order = $this->placeOrder([[$offer, 1]], $customer->id, "customer-{$label}@example.test");
        $this->payFor($order);

        // The standing cart, built after checkout has consumed its own: a
        // customer may hold exactly one active cart, and the database says
        // so. This is the cart line the matrix aims at.
        $cart = $this->cart($customer->id);
        app(AddOfferToCart::class)($cart, $offer->public_id, 2);
        /** @var CartItem $cartLine */
        $cartLine = $cart->items()->firstOrFail();

        $sellerOrder = $this->sellerOrderFor($order->id, $seller->id);
        $orderItem = $this->itemsOf($sellerOrder)[0];

        $shipment = $this->shipEverything($sellerOrder);
        $this->deliver($shipment);

        $payoutAccount = PayoutAccount::factory()->create([
            'seller_account_id' => $seller->id,
            'display_label' => "{$label} bank",
        ]);

        $payoutRequest = PayoutRequest::factory()->create([
            'seller_account_id' => $seller->id,
            'payout_account_id' => $payoutAccount->id,
        ]);

        $application = SellerApplication::factory()->create([
            'user_id' => $sellerUser->id,
            'legal_name' => "{$label} Trading Ltd",
        ]);

        $document = SellerApplicationDocument::factory()->create([
            'seller_application_id' => $application->id,
            'original_name' => "{$label}-incorporation.pdf",
        ]);

        $review = ProductReview::factory()->create([
            'product_id' => $product->id,
            'user_id' => $customer->id,
            'order_item_id' => $orderItem->id,
            'seller_order_id' => $sellerOrder->id,
        ]);

        $wishlistItem = WishlistItem::query()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
        ]);

        return [
            'label' => $label,
            'seller' => $seller,
            'store' => $store,
            'sellerUser' => $sellerUser,
            'membership' => $membership,
            'product' => $product,
            'proposal' => $proposal,
            'offer' => $offer,
            'customer' => $customer,
            'cart' => $cart,
            'cartLine' => $cartLine,
            'address' => $address,
            'order' => $order,
            'sellerOrder' => $sellerOrder,
            'orderItem' => $orderItem,
            'shipment' => $shipment,
            'payoutAccount' => $payoutAccount,
            'payoutRequest' => $payoutRequest,
            'application' => $application,
            'document' => $document,
            'review' => $review,
            'wishlistItem' => $wishlistItem,
        ];
    }
}
