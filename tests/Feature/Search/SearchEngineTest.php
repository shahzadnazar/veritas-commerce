<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Modules\Catalog\Enums\AttributeType;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Attribute;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductAttributeValue;
use App\Modules\Catalog\Queries\BuildIndexableProduct;
use App\Modules\Offers\Enums\OfferCondition;
use App\Modules\Offers\Enums\OfferStatus;
use App\Modules\Offers\Models\Offer;
use App\Modules\Search\Contracts\SearchIndex;
use App\Modules\Search\Data\SearchQuery;
use App\Modules\Search\Enums\SortOption;
use App\Modules\Sellers\Enums\SellerStatus;
use App\Modules\Sellers\Models\SellerAccount;
use App\Modules\Stores\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Inventory\StocksOffers;
use Tests\TestCase;

/**
 * The search engine itself: what it finds, in what order, and what it
 * refuses to show.
 */
final class SearchEngineTest extends TestCase
{
    use RefreshDatabase;
    use StocksOffers;

    private SearchIndex $index;

    protected function setUp(): void
    {
        parent::setUp();
        $this->index = app(SearchIndex::class);
    }

    #[Test]
    public function an_exact_title_ranks_above_a_partial_one(): void
    {
        $exact = $this->listedProduct('Aeris Kettle');
        $partial = $this->listedProduct('Aeris Kettle Descaling Tablets');

        $results = $this->index->query(new SearchQuery(phrase: 'Aeris Kettle'));

        $this->assertSame($exact->id, $results->hits[0]->productId);
        $this->assertSame(2, $results->total);
        $this->assertContains($partial->id, array_column($results->hits, 'productId'));
    }

    #[Test]
    public function a_barcode_puts_its_product_first(): void
    {
        // A well-ranked text match that should still lose to the barcode.
        $this->listedProduct('Aeris Kettle');
        $identified = $this->listedProduct('Something Else', gtin: '00012345678905');

        $results = $this->index->query(new SearchQuery(phrase: '00012345678905'));

        $this->assertSame($identified->id, $results->hits[0]->productId);
    }

    #[Test]
    public function a_brand_name_finds_that_brands_products(): void
    {
        $brand = Brand::query()->create([
            'name' => 'Aeris', 'normalised_name' => 'aeris', 'slug' => 'aeris', 'is_active' => true,
        ]);

        $mine = $this->listedProduct('Cordless Kettle', brandId: $brand->id);
        $this->listedProduct('Unrelated Toaster');

        $results = $this->index->query(new SearchQuery(phrase: 'Aeris'));

        $this->assertSame([$mine->id], array_column($results->hits, 'productId'));
    }

    #[Test]
    public function a_category_name_finds_products_in_it(): void
    {
        $category = Category::factory()->create(['name' => 'Espresso Machines']);
        $mine = $this->listedProduct('Bialetti Moka', categoryId: $category->id);
        $this->listedProduct('Unrelated Toaster');

        $results = $this->index->query(new SearchQuery(phrase: 'Espresso Machines'));

        $this->assertSame([$mine->id], array_column($results->hits, 'productId'));
    }

    #[Test]
    public function a_misspelling_still_finds_the_product(): void
    {
        $product = $this->listedProduct('iPhone 15 Pro');

        // Two of the classic transpositions from the brief.
        $results = $this->index->query(new SearchQuery(phrase: 'iphnoe'));

        $this->assertSame([$product->id], array_column($results->hits, 'productId'));
    }

    #[Test]
    public function fuzzy_matching_does_not_return_everything(): void
    {
        $this->listedProduct('Samsung Galaxy S24');
        $this->listedProduct('Cast Iron Casserole Dish');

        $results = $this->index->query(new SearchQuery(phrase: 'samsng'));

        // Typo tolerance that matched the casserole would be worse than
        // no typo tolerance: the threshold is what keeps it useful.
        $this->assertSame(1, $results->total);
        $this->assertStringContainsString('Samsung', $results->hits[0]->title);
    }

    #[Test]
    public function an_unpublished_product_is_not_searchable(): void
    {
        $draft = $this->listedProduct('Secret Kettle');
        $draft->forceFill(['status' => ProductStatus::PendingReview->value])->save();
        $this->reindexProduct($draft);

        $this->assertSame(0, $this->index->query(new SearchQuery(phrase: 'Secret Kettle'))->total);
    }

    #[Test]
    public function a_suspended_product_is_not_searchable(): void
    {
        $product = $this->listedProduct('Recalled Kettle');
        $product->forceFill(['status' => ProductStatus::Suspended->value])->save();
        $this->reindexProduct($product);

        $this->assertSame(0, $this->index->query(new SearchQuery(phrase: 'Recalled Kettle'))->total);
    }

    #[Test]
    public function a_product_whose_only_offer_is_inactive_still_resolves_but_offers_no_price(): void
    {
        $product = $this->listedProduct('Aeris Kettle');
        Offer::query()->where('product_id', $product->id)->update(['status' => OfferStatus::Suspended->value]);
        $this->reindexProduct($product);

        $results = $this->index->query(new SearchQuery(phrase: 'Aeris Kettle'));

        // §32: still published, still findable, but nothing to buy.
        $this->assertSame(1, $results->total);
        $this->assertNull($results->hits[0]->lowestPriceMinor);
        $this->assertSame(0, $results->hits[0]->offerCount);
        $this->assertFalse($results->hits[0]->inStock);
    }

    #[Test]
    public function a_suspended_sellers_offer_stops_counting(): void
    {
        $product = $this->listedProduct('Aeris Kettle');
        $seller = Offer::query()->where('product_id', $product->id)->firstOrFail()->sellerAccount;

        $seller?->forceFill(['status' => SellerStatus::Suspended->value])->save();
        $this->reindexProduct($product);

        $results = $this->index->query(new SearchQuery(phrase: 'Aeris Kettle'));

        $this->assertSame(0, $results->hits[0]->offerCount);
    }

    #[Test]
    public function three_sellers_of_the_same_product_are_one_result(): void
    {
        $product = $this->listedProduct('Aeris Kettle');
        $this->addOffer($product, 8_900);
        $this->addOffer($product, 12_500);
        $this->reindexProduct($product);

        $results = $this->index->query(new SearchQuery(phrase: 'Aeris Kettle'));

        // The whole point of a canonical catalogue: one card, three prices
        // behind it, not three cards.
        $this->assertSame(1, $results->total);
        $this->assertSame(3, $results->hits[0]->offerCount);
        $this->assertSame(8_900, $results->hits[0]->lowestPriceMinor);
        $this->assertTrue($results->hits[0]->hasPriceRange());
    }

    #[Test]
    public function the_price_filter_works_on_the_lowest_eligible_price(): void
    {
        $cheap = $this->listedProduct('Cheap Kettle', priceMinor: 5_000);
        $dear = $this->listedProduct('Dear Kettle', priceMinor: 50_000);

        $under = $this->index->query(new SearchQuery(maxPriceMinor: 10_000));
        $over = $this->index->query(new SearchQuery(minPriceMinor: 10_000));

        $this->assertSame([$cheap->id], array_column($under->hits, 'productId'));
        $this->assertSame([$dear->id], array_column($over->hits, 'productId'));
    }

    #[Test]
    public function the_brand_filter_narrows_to_that_brand(): void
    {
        $brand = Brand::query()->create([
            'name' => 'Aeris', 'normalised_name' => 'aeris', 'slug' => 'aeris', 'is_active' => true,
        ]);
        $mine = $this->listedProduct('Kettle One', brandId: $brand->id);
        $this->listedProduct('Kettle Two');

        $results = $this->index->query(new SearchQuery(brandIds: [$brand->id]));

        $this->assertSame([$mine->id], array_column($results->hits, 'productId'));
    }

    #[Test]
    public function the_category_filter_includes_descendants(): void
    {
        $parent = Category::factory()->create(['name' => 'Kitchen']);
        $child = Category::factory()->create(['name' => 'Kettles', 'parent_id' => $parent->id]);

        $inChild = $this->listedProduct('Aeris Kettle', categoryId: $child->id);
        $this->listedProduct('Garden Spade');

        // A category page shows everything beneath it, not only its own
        // direct children.
        $results = $this->index->query(new SearchQuery(categoryId: $parent->id));

        $this->assertSame([$inChild->id], array_column($results->hits, 'productId'));
    }

    #[Test]
    public function the_condition_filter_matches_the_offers_that_exist(): void
    {
        $refurbished = $this->listedProduct('Refurbished Kettle', condition: OfferCondition::Refurbished);
        $this->listedProduct('New Kettle');

        $results = $this->index->query(new SearchQuery(conditions: [OfferCondition::Refurbished->value]));

        $this->assertSame([$refurbished->id], array_column($results->hits, 'productId'));
    }

    #[Test]
    public function a_category_defined_attribute_can_be_filtered_on(): void
    {
        $category = Category::factory()->create(['name' => 'Phones']);
        $storage = Attribute::factory()->ofType(AttributeType::Integer)->create([
            'code' => 'storage', 'name' => 'Storage', 'unit' => 'GB', 'is_filterable' => true,
        ]);
        $category->attributes()->attach($storage->id, ['is_required' => false]);

        $big = $this->listedProduct('Phone 256', categoryId: $category->id);
        $small = $this->listedProduct('Phone 128', categoryId: $category->id);

        ProductAttributeValue::query()->create([
            'product_id' => $big->id, 'attribute_id' => $storage->id, 'value_int' => 256,
        ]);
        ProductAttributeValue::query()->create([
            'product_id' => $small->id, 'attribute_id' => $storage->id, 'value_int' => 128,
        ]);

        $this->reindexProduct($big);
        $this->reindexProduct($small);

        $results = $this->index->query(new SearchQuery(attributes: ['storage' => ['256']]));

        $this->assertSame([$big->id], array_column($results->hits, 'productId'));
    }

    #[Test]
    public function price_ascending_and_descending_are_exact_mirrors(): void
    {
        $cheap = $this->listedProduct('Kettle A', priceMinor: 5_000);
        $middle = $this->listedProduct('Kettle B', priceMinor: 15_000);
        $dear = $this->listedProduct('Kettle C', priceMinor: 50_000);

        $ascending = $this->index->query(new SearchQuery(sort: SortOption::PriceAscending));
        $descending = $this->index->query(new SearchQuery(sort: SortOption::PriceDescending));

        $this->assertSame(
            [$cheap->id, $middle->id, $dear->id],
            array_column($ascending->hits, 'productId'),
        );
        $this->assertSame(
            [$dear->id, $middle->id, $cheap->id],
            array_column($descending->hits, 'productId'),
        );
    }

    #[Test]
    public function newest_orders_by_publication_not_by_indexing(): void
    {
        $older = $this->listedProduct('Older Kettle');
        $newer = $this->listedProduct('Newer Kettle');

        $older->forceFill(['published_at' => now()->subDays(30)])->save();
        $newer->forceFill(['published_at' => now()])->save();

        // Reindexed oldest-last on purpose: a reindex must not reorder the
        // catalogue.
        $this->reindexProduct($newer);
        $this->reindexProduct($older);

        $results = $this->index->query(new SearchQuery(sort: SortOption::Newest));

        $this->assertSame([$newer->id, $older->id], array_column($results->hits, 'productId'));
    }

    #[Test]
    public function relevance_falls_back_to_newest_when_there_is_no_query(): void
    {
        // Ordering a browse page by "relevance to nothing" would be an
        // arbitrary order presented as a considered one.
        $this->assertSame(SortOption::Newest, SortOption::Relevance->resolvedFor(false));
        $this->assertSame(SortOption::Relevance, SortOption::Relevance->resolvedFor(true));
    }

    #[Test]
    public function an_in_stock_product_outranks_an_identical_out_of_stock_one(): void
    {
        // Same title, same price, same everything but availability.
        $empty = $this->listedProduct('Aeris Cordless Kettle', stock: 0);
        $stocked = $this->listedProduct('Aeris Cordless Kettle');

        $results = $this->index->query(new SearchQuery(phrase: 'Aeris Cordless Kettle'));

        $this->assertSame(
            [$stocked->id, $empty->id],
            array_column($results->hits, 'productId'),
            'A buyable result outranks an identical unbuyable one.',
        );
    }

    #[Test]
    public function the_availability_tie_break_applies_to_every_sort(): void
    {
        // Same price and same publication date, so availability is the
        // only thing left to order them by.
        $empty = $this->listedProduct('Kettle A', priceMinor: 9_900, stock: 0);
        $stocked = $this->listedProduct('Kettle B', priceMinor: 9_900);

        $moment = now()->subDay();
        Product::query()->whereIn('id', [$empty->id, $stocked->id])->update(['published_at' => $moment]);

        $this->reindexProduct($empty);
        $this->reindexProduct($stocked);

        /*
         * §1: the rule is a default tie-break, not a filter, and it lives
         * in one place — so a sort added later cannot quietly forget it.
         */
        foreach ([SortOption::PriceAscending, SortOption::PriceDescending, SortOption::Newest] as $sort) {
            $results = $this->index->query(new SearchQuery(sort: $sort));

            $this->assertSame(
                $stocked->id,
                $results->hits[0]->productId,
                "Availability did not break the tie under {$sort->value}.",
            );
        }
    }

    #[Test]
    public function availability_never_outranks_a_genuinely_cheaper_result(): void
    {
        $cheapButEmpty = $this->listedProduct('Kettle A', priceMinor: 5_000, stock: 0);
        $dearButStocked = $this->listedProduct('Kettle B', priceMinor: 50_000);

        $results = $this->index->query(new SearchQuery(sort: SortOption::PriceAscending));

        // A tie-break breaks ties. Sorting by price and getting the dearer
        // product first would be the control lying about what it does.
        $this->assertSame(
            [$cheapButEmpty->id, $dearButStocked->id],
            array_column($results->hits, 'productId'),
        );
    }

    #[Test]
    public function results_are_paged(): void
    {
        foreach (range(1, 5) as $index) {
            $this->listedProduct("Kettle {$index}", priceMinor: 1_000 * $index);
        }

        $first = $this->index->query(new SearchQuery(sort: SortOption::PriceAscending, page: 1, perPage: 2));
        $second = $this->index->query(new SearchQuery(sort: SortOption::PriceAscending, page: 2, perPage: 2));

        $this->assertSame(5, $first->total);
        $this->assertSame(3, $first->lastPage());
        $this->assertCount(2, $first->hits);
        $this->assertNotSame(
            array_column($first->hits, 'productId'),
            array_column($second->hits, 'productId'),
        );
    }

    #[Test]
    public function a_query_matching_nothing_returns_an_empty_result_rather_than_everything(): void
    {
        $this->listedProduct('Aeris Kettle');

        $results = $this->index->query(new SearchQuery(phrase: 'zzzzqqqq'));

        $this->assertTrue($results->isEmpty());
        $this->assertSame(0, $results->total);
    }

    #[Test]
    public function a_near_miss_offers_a_spelling_suggestion(): void
    {
        $this->listedProduct('Sennheiser Momentum');

        $results = $this->index->query(new SearchQuery(phrase: 'sennheiserr momentum'));

        // Either it found it outright or it says what was meant; both are
        // acceptable, silence is not.
        $this->assertTrue($results->total > 0 || $results->suggestion !== null);
    }

    /**
     * A published product with one eligible, stocked offer.
     *
     * Named parameters rather than an attribute bag: the three things a
     * search test ever needs to vary are the category, the brand and the
     * barcode, and spelling them out keeps the factory call type-checked.
     */
    private function listedProduct(
        string $title,
        ?int $categoryId = null,
        ?int $brandId = null,
        ?string $gtin = null,
        int $priceMinor = 9_900,
        ?OfferCondition $condition = null,
        int $stock = 10,
    ): Product {
        $product = Product::factory()->create([
            'title' => $title,
            'status' => ProductStatus::Published->value,
            'published_at' => now(),
            'category_id' => $categoryId ?? Category::factory()->create()->id,
            'brand_id' => $brandId,
            'gtin' => $gtin,
        ]);

        $this->addOffer($product, $priceMinor, $condition, $stock);
        $this->reindexProduct($product);

        return $product;
    }

    private function addOffer(
        Product $product,
        int $priceMinor,
        ?OfferCondition $condition = null,
        int $stock = 10,
    ): Offer {
        $seller = SellerAccount::factory()->create(['status' => SellerStatus::Approved->value]);
        $store = Store::factory()->create(['seller_account_id' => $seller->id, 'is_open' => true]);

        $offer = Offer::factory()->create([
            'seller_account_id' => $seller->id,
            'store_id' => $store->id,
            'product_id' => $product->id,
            'product_variant_id' => null,
            'price_minor' => $priceMinor,
            'condition' => ($condition ?? OfferCondition::New)->value,
            'status' => OfferStatus::Published->value,
        ]);

        // Stocked by default, so availability-sensitive assertions have
        // something to measure rather than defaulting to zero everywhere.
        // Pass zero where the test is about an empty listing.
        if ($stock > 0) {
            $this->stockOffer($offer, $stock);
        }

        return $offer;
    }

    private function reindexProduct(Product $product): void
    {
        $document = app(BuildIndexableProduct::class)->describe($product->id);

        if ($document === null) {
            $this->index->forget($product->id);

            return;
        }

        $this->index->index($document);
    }
}
