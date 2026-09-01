<?php

declare(strict_types=1);

namespace Tests\Feature\Catalogue;

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductMedia;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Offers\Enums\OfferCondition;
use App\Modules\Offers\Enums\OfferStatus;
use App\Modules\Offers\Models\Offer;
use App\Modules\Sellers\Enums\SellerStatus;
use App\Modules\Sellers\Models\SellerAccount;
use App\Modules\Stores\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The canonical product page and what it tells a search engine.
 */
final class PublicProductPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
    }

    #[Test]
    public function a_published_product_with_a_seller_resolves(): void
    {
        $product = Product::factory()->status(ProductStatus::Published)->create(['slug' => 'copper-moka-pot']);
        $this->offerOn($product);

        $this->get('/products/copper-moka-pot')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Product/Show')
                ->where('product.title', $product->title)
                ->has('offers', 1)
                ->has('breadcrumbs'));
    }

    #[Test]
    public function an_unpublished_product_has_no_public_page_at_all(): void
    {
        foreach ([ProductStatus::Draft, ProductStatus::PendingReview, ProductStatus::Rejected, ProductStatus::Approved] as $status) {
            $product = Product::factory()->status($status)->create();
            $this->offerOn($product);

            // 404, not an empty shell: a page for a product nobody can buy
            // is still indexed and still linkable.
            $this->get('/products/'.$product->slug)->assertNotFound();
        }
    }

    #[Test]
    public function a_suspended_product_stops_resolving_and_takes_its_offers_with_it(): void
    {
        $product = Product::factory()->status(ProductStatus::Published)->create();
        $this->offerOn($product);

        $this->get('/products/'.$product->slug)->assertOk();

        $product->forceFill(['status' => ProductStatus::Suspended->value])->save();

        $this->get('/products/'.$product->slug)->assertNotFound();
    }

    #[Test]
    public function several_sellers_appear_on_one_page_ranked_by_price(): void
    {
        $product = Product::factory()->status(ProductStatus::Published)->create();

        $this->offerOn($product, 12_200);
        $this->offerOn($product, 11_750);
        $this->offerOn($product, 11_990);

        $this->get('/products/'.$product->slug)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('offers', 3)
                ->where('offers.0.priceMinor', 11_750)
                ->where('offers.1.priceMinor', 11_990)
                ->where('offers.2.priceMinor', 12_200)
                ->where('priceRange.fromMinor', 11_750)
                ->where('priceRange.toMinor', 12_200)
                ->where('priceRange.isSingle', false));
    }

    #[Test]
    public function only_eligible_offers_are_shown(): void
    {
        $product = Product::factory()->status(ProductStatus::Published)->create();

        $this->offerOn($product, 10_000);

        $suspended = $this->offerOn($product, 9_000);
        $suspended->sellerAccount?->forceFill(['status' => SellerStatus::Suspended->value])->save();

        $closed = $this->offerOn($product, 8_000);
        $closed->store?->forceFill(['is_open' => false])->save();

        $draft = $this->offerOn($product, 7_000);
        $draft->forceFill(['status' => OfferStatus::Draft->value])->save();

        // The cheapest three are all ineligible; the page must not
        // advertise a price nobody can buy at.
        $this->get('/products/'.$product->slug)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('offers', 1)->where('offers.0.priceMinor', 10_000));
    }

    #[Test]
    public function a_product_nobody_lists_resolves_but_is_not_indexed(): void
    {
        $product = Product::factory()->status(ProductStatus::Published)->create();

        $this->get('/products/'.$product->slug)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('offers', 0)
                ->where('priceRange', null)
                // Nothing to buy, nothing to rank for.
                ->where('seo.robots', 'noindex, follow'));
    }

    #[Test]
    public function a_renamed_product_redirects_from_its_old_address(): void
    {
        $product = Product::factory()->status(ProductStatus::Published)->create(['slug' => 'new-address']);
        $this->offerOn($product);

        DB::table('product_slug_history')->insert([
            'product_id' => $product->id,
            'old_slug' => 'old-address',
            'changed_at' => now(),
        ]);

        $this->get('/products/old-address')->assertRedirect('/products/new-address');
    }

    #[Test]
    public function a_merged_product_redirects_to_the_one_it_became(): void
    {
        $survivor = Product::factory()->status(ProductStatus::Published)->create(['slug' => 'the-survivor']);
        $this->offerOn($survivor);

        $merged = Product::factory()->status(ProductStatus::Published)->create(['slug' => 'the-duplicate']);
        $merged->forceFill(['merged_into_product_id' => $survivor->id, 'merged_at' => now()])->save();

        // The address accumulated authority that belongs to the product it
        // became.
        $this->get('/products/the-duplicate')->assertRedirect('/products/the-survivor');
    }

    #[Test]
    public function an_unknown_address_is_a_404(): void
    {
        $this->get('/products/never-existed')->assertNotFound();
    }

    #[Test]
    public function the_page_carries_its_seo_identity(): void
    {
        $brand = Brand::factory()->create(['name' => 'Northline Audio']);
        $product = Product::factory()->status(ProductStatus::Published)->create([
            'slug' => 'studio-headphones',
            'title' => 'Studio Reference Headphones',
            'brand_id' => $brand->id,
            'description' => 'Closed-back monitoring headphones with a replaceable cable.',
        ]);
        $this->offerOn($product);

        $this->get('/products/studio-headphones')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('seo.title', 'Studio Reference Headphones')
                ->where('seo.robots', 'index, follow')
                ->where('seo.canonical', fn (string $url) => str_ends_with($url, '/products/studio-headphones'))
                ->where('seo.ogType', 'product')
                ->has('seo.description'));
    }

    #[Test]
    public function the_structured_data_reflects_real_database_values(): void
    {
        $brand = Brand::factory()->create(['name' => 'Northline Audio']);
        $product = Product::factory()->status(ProductStatus::Published)->create([
            'slug' => 'jsonld-product',
            'title' => 'Studio Reference Headphones',
            'brand_id' => $brand->id,
            'ean' => '9780306406157',
        ]);
        $this->offerOn($product, 14_999, OfferCondition::New);

        $response = $this->get('/products/jsonld-product')->assertOk();

        $response->assertInertia(function ($page) {
            /** @var array<int, array<string, mixed>> $documents */
            $documents = $page->toArray()['props']['structuredData'];
            $product = $documents[0];

            $this->assertSame('Product', $product['@type']);
            $this->assertSame('Studio Reference Headphones', $product['name']);
            $this->assertSame('9780306406157', $product['gtin13']);
            $this->assertSame('Northline Audio', $product['brand']['name']);

            // One seller, so a single Offer with the real price.
            $this->assertSame('Offer', $product['offers']['@type']);
            $this->assertSame('149.99', $product['offers']['price']);
            $this->assertSame('https://schema.org/NewCondition', $product['offers']['itemCondition']);

            // And the breadcrumb trail, which is real navigation.
            $this->assertSame('BreadcrumbList', $documents[1]['@type']);
        });
    }

    #[Test]
    public function the_structured_data_never_claims_a_rating_or_a_review(): void
    {
        $product = Product::factory()->status(ProductStatus::Published)->create(['slug' => 'no-reviews']);
        $this->offerOn($product);

        $this->get('/products/no-reviews')->assertInertia(function ($page) {
            /** @var array<int, array<string, mixed>> $documents */
            $documents = $page->toArray()['props']['structuredData'];

            // There are no reviews in the database, so there are none in
            // the markup. A rich result showing four stars for a product
            // nobody has reviewed costs the domain's standing.
            foreach (['aggregateRating', 'review', 'reviewCount', 'ratingValue'] as $claim) {
                $this->assertFalse(
                    $this->keyExistsAnywhere($documents, $claim),
                    "The markup claims {$claim}, which the database cannot support.",
                );
            }
        });
    }

    #[Test]
    public function several_sellers_produce_an_aggregate_offer_with_a_real_range(): void
    {
        $product = Product::factory()->status(ProductStatus::Published)->create(['slug' => 'aggregate-product']);
        $this->offerOn($product, 11_750);
        $this->offerOn($product, 12_200);

        $this->get('/products/aggregate-product')->assertInertia(function ($page) {
            $offers = $page->toArray()['props']['structuredData'][0]['offers'];

            $this->assertSame('AggregateOffer', $offers['@type']);
            $this->assertSame('117.50', $offers['lowPrice']);
            $this->assertSame('122.00', $offers['highPrice']);
            $this->assertSame(2, $offers['offerCount']);
        });
    }

    #[Test]
    public function a_product_nobody_lists_emits_no_price_at_all(): void
    {
        $product = Product::factory()->status(ProductStatus::Published)->create(['slug' => 'unpriced']);

        $this->get('/products/unpriced')->assertInertia(function ($page) {
            $document = $page->toArray()['props']['structuredData'][0];

            // No offers means no price. Inventing one would be a lie a
            // search engine repeats.
            $this->assertArrayNotHasKey('offers', $document);
        });
    }

    #[Test]
    public function only_processed_images_are_shown(): void
    {
        $product = Product::factory()->status(ProductStatus::Published)->create();
        $this->offerOn($product);

        ProductMedia::factory()->primary()->create(['product_id' => $product->id]);
        ProductMedia::factory()->create([
            'product_id' => $product->id,
            'processing_state' => ProductMedia::STATE_PENDING,
            'processed_at' => null,
        ]);

        // A half-processed upload should not appear as a broken frame.
        $this->get('/products/'.$product->slug)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('media', 1));
    }

    #[Test]
    public function a_seller_offer_never_gets_a_page_of_its_own(): void
    {
        $product = Product::factory()->status(ProductStatus::Published)->create();
        $offer = $this->offerOn($product);

        // Four sellers listing one kettle must not become four competing
        // pages splitting the authority of one.
        $this->get('/offers/'.$offer->public_id)->assertNotFound();
        $this->get('/products/'.$offer->public_id)->assertNotFound();
    }

    #[Test]
    public function the_product_page_holds_its_query_count(): void
    {
        $category = Category::factory()->create();
        $product = Product::factory()->status(ProductStatus::Published)->create(['category_id' => $category->id]);

        // The normal case, not a trivial one: several images, several
        // variants, several sellers.
        ProductMedia::factory()->count(4)->create(['product_id' => $product->id]);
        ProductVariant::factory()->count(3)->create(['product_id' => $product->id]);

        foreach (range(1, 5) as $ignored) {
            $this->offerOn($product);
        }

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get('/products/'.$product->slug)->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // A bound, not a target: the point is that adding a sixth seller
        // does not add five queries.
        $this->assertLessThanOrEqual(
            25,
            $queries,
            "The product page ran {$queries} queries; something is loading lazily.",
        );
    }

    #[Test]
    public function adding_more_sellers_does_not_add_more_queries(): void
    {
        $small = Product::factory()->status(ProductStatus::Published)->create();
        $this->offerOn($small);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get('/products/'.$small->slug)->assertOk();
        $withOne = count(DB::getQueryLog());
        DB::disableQueryLog();

        $large = Product::factory()->status(ProductStatus::Published)->create();
        foreach (range(1, 8) as $ignored) {
            $this->offerOn($large);
        }

        DB::enableQueryLog();
        // The log is not cleared by enabling it, and reading the previous
        // measurement's entries again would look exactly like an N+1.
        DB::flushQueryLog();
        $this->get('/products/'.$large->slug)->assertOk();
        $withEight = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(
            $withOne,
            $withEight,
            'Eight sellers cost the same number of queries as one, or something is N+1.',
        );
    }

    /** @param  array<mixed>  $data */
    private function keyExistsAnywhere(array $data, string $needle): bool
    {
        foreach ($data as $key => $value) {
            if ($key === $needle) {
                return true;
            }

            if (is_array($value) && $this->keyExistsAnywhere($value, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function offerOn(Product $product, int $priceMinor = 9_900, ?OfferCondition $condition = null): Offer
    {
        $seller = SellerAccount::factory()->create();
        $store = Store::factory()->create(['seller_account_id' => $seller->id, 'is_open' => true]);

        return Offer::factory()->create([
            'seller_account_id' => $seller->id,
            'store_id' => $store->id,
            'product_id' => $product->id,
            'product_variant_id' => null,
            'price_minor' => $priceMinor,
            'condition' => ($condition ?? OfferCondition::New)->value,
            'status' => OfferStatus::Published->value,
        ]);
    }
}
