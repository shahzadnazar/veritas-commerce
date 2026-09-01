<?php

declare(strict_types=1);

namespace Tests\Feature\Discovery;

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Actions\AdjustInventory;
use App\Modules\Inventory\Enums\InventoryMovementReason;
use App\Modules\Offers\Enums\OfferStatus;
use App\Modules\Offers\Models\Offer;
use App\Modules\Sellers\Enums\SellerStatus;
use App\Modules\Sellers\Models\SellerAccount;
use App\Modules\Stores\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Inventory\StocksOffers;
use Tests\TestCase;

/**
 * What the product page tells a search engine about stock.
 *
 * The rule carried from M2 and extended here: never claim what the
 * database cannot support. M2 could only say "a seller lists this"; there
 * is an inventory ledger now, so the claim has to match it — a page that
 * says InStock and then cannot fulfil is how a marketplace earns a manual
 * penalty.
 */
final class StructuredDataAvailabilityTest extends TestCase
{
    use BuildsCatalogue;
    use RefreshDatabase;
    use StocksOffers;

    #[Test]
    public function a_stocked_product_is_declared_in_stock(): void
    {
        ['product' => $product, 'offer' => $offer] = $this->listedProduct();
        $this->stockOffer($offer, 10);

        $data = $this->structuredData($product);

        $this->assertSame('https://schema.org/InStock', $data['offers']['availability'] ?? null);
    }

    #[Test]
    public function a_sold_out_product_is_declared_out_of_stock(): void
    {
        ['product' => $product, 'offer' => $offer] = $this->listedProduct();
        $this->stockOffer($offer, 4);
        app(AdjustInventory::class)($offer, -4, InventoryMovementReason::Damaged, 'seller', 1);

        $data = $this->structuredData($product);

        $this->assertSame('https://schema.org/OutOfStock', $data['offers']['availability'] ?? null);
    }

    #[Test]
    public function a_product_with_no_stock_record_at_all_is_declared_out_of_stock(): void
    {
        // Never counted is not the same as counted at zero, and both mean
        // the same thing to a customer: they cannot have it.
        ['product' => $product] = $this->listedProduct();

        $data = $this->structuredData($product);

        $this->assertSame('https://schema.org/OutOfStock', $data['offers']['availability'] ?? null);
    }

    #[Test]
    public function several_sellers_produce_an_aggregate_whose_availability_is_still_truthful(): void
    {
        ['product' => $product, 'offer' => $first] = $this->listedProduct();

        $second = $this->addOffer($product, 12_000);
        $this->stockOffer($second, 6);

        $data = $this->structuredData($product);

        $this->assertSame('AggregateOffer', $data['offers']['@type'] ?? null);
        // One seller has stock, so the product is buyable — even though
        // the first seller has none.
        $this->assertSame('https://schema.org/InStock', $data['offers']['availability'] ?? null);
        $this->assertSame(2, $data['offers']['offerCount'] ?? null);
        $this->assertSame(0, $first->fresh()?->getAttribute('available_stock') ?? 0);
    }

    #[Test]
    public function nothing_ever_claims_a_rating_or_a_review(): void
    {
        ['product' => $product, 'offer' => $offer] = $this->listedProduct();
        $this->stockOffer($offer, 10);

        $encoded = json_encode($this->structuredData($product)) ?: '';

        // No review module exists. Emitting either would be inventing
        // social proof, which is the fastest route to a manual penalty.
        $this->assertStringNotContainsString('aggregateRating', $encoded);
        $this->assertStringNotContainsString('"review"', $encoded);
        $this->assertStringNotContainsString('ratingValue', $encoded);
    }

    /** @return array<string, mixed> */
    private function structuredData(Product $product): array
    {
        $response = $this->get('/products/'.$product->slug)->assertOk();

        /** @var array<int, array<string, mixed>> $documents */
        $documents = $response->viewData('page')['props']['structuredData'] ?? [];

        foreach ($documents as $document) {
            if (($document['@type'] ?? null) === 'Product') {
                return $document;
            }
        }

        $this->fail('The product page emitted no Product structured data.');
    }

    /** @return array{product: Product, offer: Offer} */
    private function listedProduct(): array
    {
        $product = Product::factory()->create([
            'title' => 'Aeris Cordless Kettle',
            'status' => ProductStatus::Published->value,
            'published_at' => now(),
        ]);

        return ['product' => $product, 'offer' => $this->addOffer($product, 9_900)];
    }

    private function addOffer(Product $product, int $priceMinor): Offer
    {
        $seller = SellerAccount::factory()->create(['status' => SellerStatus::Approved->value]);
        $store = Store::factory()->create(['seller_account_id' => $seller->id, 'is_open' => true]);

        return Offer::factory()->create([
            'seller_account_id' => $seller->id,
            'store_id' => $store->id,
            'product_id' => $product->id,
            'product_variant_id' => null,
            'price_minor' => $priceMinor,
            'status' => OfferStatus::Published->value,
        ]);
    }
}
