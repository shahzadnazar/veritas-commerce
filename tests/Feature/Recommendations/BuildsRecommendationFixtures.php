<?php

declare(strict_types=1);

namespace Tests\Feature\Recommendations;

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Queries\BuildIndexableProduct;
use App\Modules\Events\Enums\InteractionEventType;
use App\Modules\Events\Models\InteractionEvent;
use App\Modules\Inventory\Actions\AdjustInventory;
use App\Modules\Inventory\Enums\InventoryMovementReason;
use App\Modules\Offers\Enums\OfferStatus;
use App\Modules\Offers\Models\Offer;
use App\Modules\Search\Contracts\SearchIndex;
use App\Modules\Sellers\Enums\SellerStatus;
use App\Modules\Sellers\Models\SellerAccount;
use App\Modules\Stores\Models\Store;

/**
 * Catalogue and behaviour fixtures for the recommendation tests.
 *
 * Products go through the real indexer rather than having a search
 * document inserted for them, because the recommendation eligibility gate
 * reads that document — a hand-written one would prove the gate works
 * against a fixture rather than against the catalogue.
 */
trait BuildsRecommendationFixtures
{
    /**
     * A published product with one seller offering it, indexed.
     *
     * @return array{product: Product, offer: Offer, seller: SellerAccount}
     */
    protected function listedProduct(
        string $title = 'Aeris Cordless Kettle',
        int $priceMinor = 9_900,
        int $stock = 10,
        ?Category $category = null,
        ?Brand $brand = null,
        ?SellerAccount $seller = null,
    ): array {
        // Only override what the caller cared about: products.category_id
        // is NOT NULL, so passing a null through would fight the factory
        // rather than accept its default.
        $attributes = [
            'title' => $title,
            'status' => ProductStatus::Published->value,
            'published_at' => now(),
        ];

        if ($category !== null) {
            $attributes['category_id'] = $category->id;
        }

        if ($brand !== null) {
            $attributes['brand_id'] = $brand->id;
        }

        $product = Product::factory()->create($attributes);

        $seller ??= SellerAccount::factory()->create(['status' => SellerStatus::Approved->value]);
        $offer = $this->offerFor($product, $priceMinor, $stock, $seller);

        $this->reindex($product);

        return ['product' => $product->refresh(), 'offer' => $offer, 'seller' => $seller];
    }

    /** A second, third, fourth seller listing the same canonical product. */
    protected function offerFor(
        Product $product,
        int $priceMinor = 9_900,
        int $stock = 10,
        ?SellerAccount $seller = null,
    ): Offer {
        $seller ??= SellerAccount::factory()->create(['status' => SellerStatus::Approved->value]);
        $store = Store::factory()->create(['seller_account_id' => $seller->id, 'is_open' => true]);

        $offer = Offer::factory()->create([
            'seller_account_id' => $seller->id,
            'store_id' => $store->id,
            'product_id' => $product->id,
            'product_variant_id' => null,
            'price_minor' => $priceMinor,
            'status' => OfferStatus::Published->value,
        ]);

        app(AdjustInventory::class)(
            $offer,
            $stock,
            InventoryMovementReason::RestockReceived,
            'seller',
            (int) $seller->id,
        );

        return $offer->refresh();
    }

    /** Push the catalogue's current truth into the search index. */
    protected function reindex(Product $product): void
    {
        $index = app(SearchIndex::class);
        $document = app(BuildIndexableProduct::class)->describe((int) $product->id);

        if ($document === null) {
            $index->forget((int) $product->id);

            return;
        }

        $index->index($document);
    }

    /** One visitor viewing one product, at a point in time. */
    protected function viewed(
        Product $product,
        ?int $userId = null,
        ?string $session = null,
        ?string $at = null,
    ): InteractionEvent {
        return $this->event(InteractionEventType::ProductViewed, $product, $userId, $session, $at);
    }

    protected function event(
        InteractionEventType $type,
        Product $product,
        ?int $userId = null,
        ?string $session = null,
        ?string $at = null,
    ): InteractionEvent {
        /** @var InteractionEvent $event */
        $event = InteractionEvent::query()->create([
            'user_id' => $userId,
            'anonymous_session_id' => $userId === null ? ($session ?? 'session-a') : null,
            'event_type' => $type->value,
            'product_id' => $product->id,
            'created_at' => $at ?? now()->toDateTimeString(),
        ]);

        return $event;
    }
}
