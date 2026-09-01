<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use App\Modules\Cart\Models\Cart;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Actions\AdjustInventory;
use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Offers\Enums\OfferStatus;
use App\Modules\Offers\Models\Offer;
use App\Modules\Sellers\Enums\SellerStatus;
use App\Modules\Sellers\Models\SellerAccount;
use App\Modules\Stores\Models\Store;

/**
 * A published product, an eligible seller and a stocked offer.
 *
 * Built the way the application builds them — stock arrives through the
 * ledger — so a fixture cannot create a state the system could not.
 */
trait BuildsCommerceFixtures
{
    /**
     * @return array{offer: Offer, product: Product, seller: SellerAccount, store: Store}
     */
    protected function sellableOffer(
        string $title = 'Aeris Cordless Kettle',
        int $priceMinor = 9_900,
        int $stock = 10,
        ?SellerAccount $seller = null,
        ?Store $store = null,
    ): array {
        $seller ??= SellerAccount::factory()->create(['status' => SellerStatus::Approved->value]);
        $store ??= Store::factory()->create(['seller_account_id' => $seller->id, 'is_open' => true]);

        $product = Product::factory()->create([
            'title' => $title,
            'status' => ProductStatus::Published->value,
            'published_at' => now(),
        ]);

        $offer = Offer::factory()->create([
            'seller_account_id' => $seller->id,
            'store_id' => $store->id,
            'product_id' => $product->id,
            'product_variant_id' => null,
            'price_minor' => $priceMinor,
            'status' => OfferStatus::Published->value,
        ]);

        $this->stock($offer, $stock);

        return ['offer' => $offer->refresh(), 'product' => $product, 'seller' => $seller, 'store' => $store];
    }

    /** An active cart owned by a customer, or by an anonymous browser. */
    protected function cart(?int $userId = null, string $sessionToken = 'test-session'): Cart
    {
        return Cart::query()->create([
            'user_id' => $userId,
            'session_token' => $userId === null ? $sessionToken : null,
            'status' => 'active',
            'last_activity_at' => now(),
        ]);
    }

    protected function stock(Offer $offer, int $quantity): InventoryBalance
    {
        $location = InventoryLocation::query()->firstOrCreate(
            ['seller_account_id' => $offer->seller_account_id, 'is_default' => true],
            ['name' => 'Default'],
        );

        $balance = InventoryBalance::query()->firstOrCreate(
            ['offer_id' => $offer->id, 'inventory_location_id' => $location->id],
            ['on_hand' => 0],
        );

        if ($quantity > 0) {
            app(AdjustInventory::class)->openingStock($offer, $quantity, 'seller', 1);
        }

        return $balance->refresh();
    }
}
