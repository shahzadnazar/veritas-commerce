<?php

declare(strict_types=1);

namespace Tests\Feature\Discovery;

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Actions\AdjustInventory;
use App\Modules\Inventory\Enums\InventoryMovementReason;
use App\Modules\Offers\Enums\OfferStatus;
use App\Modules\Offers\Models\Offer;
use App\Modules\Sellers\Enums\SellerStatus;
use App\Modules\Sellers\Models\SellerAccount;
use App\Modules\Stores\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Inventory\StocksOffers;
use Tests\TestCase;

/**
 * The three listing surfaces, over HTTP.
 *
 * They share an engine, so the interesting assertions are the ones about
 * consistency: the same product, the same price and the same availability
 * however a customer arrived.
 */
final class DiscoveryPageTest extends TestCase
{
    use BuildsCatalogue;
    use RefreshDatabase;
    use StocksOffers;

    #[Test]
    public function a_category_page_lists_its_eligible_products_as_canonical_cards(): void
    {
        $category = Category::factory()->create(['name' => 'Kettles']);
        $product = $this->listedProduct('Aeris Kettle', $category);

        // A second and third seller of the same product.
        $this->addOffer($product, 8_900);
        $this->addOffer($product, 12_000);
        $this->reindex($product);

        $this->get('/categories/'.$category->slug)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Category/Show')
                // Three sellers, one card. The whole point of a canonical
                // catalogue.
                ->has('results.data', 1)
                ->where('results.data.0.title', 'Aeris Kettle')
                ->where('results.data.0.offerCount', 3));
    }

    #[Test]
    public function a_category_page_includes_products_in_its_descendants(): void
    {
        $parent = Category::factory()->create(['name' => 'Kitchen']);
        $child = Category::factory()->create(['name' => 'Kettles', 'parent_id' => $parent->id]);

        $this->listedProduct('Aeris Kettle', $child);

        $this->get('/categories/'.$parent->slug)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('results.data', 1));
    }

    #[Test]
    public function child_categories_are_offered_for_navigation(): void
    {
        $parent = Category::factory()->create(['name' => 'Kitchen']);
        $visible = Category::factory()->create(['name' => 'Kettles', 'parent_id' => $parent->id]);
        Category::factory()->create([
            'name' => 'Retired', 'parent_id' => $parent->id, 'is_visible' => false,
        ]);

        $this->get('/categories/'.$parent->slug)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('children', 1)
                ->where('children.0.slug', $visible->slug));
    }

    #[Test]
    public function the_breadcrumb_walks_the_real_lineage(): void
    {
        $root = Category::factory()->create(['name' => 'Kitchen']);
        $middle = Category::factory()->create(['name' => 'Appliances', 'parent_id' => $root->id]);
        $leaf = Category::factory()->create(['name' => 'Kettles', 'parent_id' => $middle->id]);

        $this->get('/categories/'.$leaf->slug)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('breadcrumbs', 3)
                ->where('breadcrumbs.0.name', 'Kitchen')
                ->where('breadcrumbs.2.name', 'Kettles'));
    }

    #[Test]
    public function a_clean_category_page_indexes_and_a_filtered_one_does_not(): void
    {
        $category = Category::factory()->create(['name' => 'Kettles']);
        $this->listedProduct('Aeris Kettle', $category);

        $url = '/categories/'.$category->slug;
        $canonical = rtrim((string) config('veritas.identity.public_url'), '/').$url;

        $this->get($url)->assertOk()->assertInertia(fn ($page) => $page
            ->where('seo.robots', 'index, follow')
            ->where('seo.canonical', $canonical));

        // Every faceted permutation points back at the clean URL and stays
        // out of the index, or one category becomes six hundred pages.
        foreach (['?in_stock=1', '?page=2', '?sort=price_asc&in_stock=1'] as $suffix) {
            $this->get($url.$suffix)->assertOk()->assertInertia(fn ($page) => $page
                ->where('seo.robots', 'noindex, follow')
                ->where('seo.canonical', $canonical));
        }
    }

    #[Test]
    public function a_store_page_lists_only_that_sellers_products(): void
    {
        ['product' => $mine, 'store' => $store] = $this->storeWithProduct('Aeris Kettle');
        $this->storeWithProduct('Someone Elses Toaster');

        $this->get('/stores/'.$store->slug)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Store/Show')
                ->has('results.data', 1)
                ->where('results.data.0.title', 'Aeris Kettle'));
    }

    #[Test]
    public function a_product_sold_by_several_sellers_appears_in_each_of_their_stores(): void
    {
        ['product' => $product, 'store' => $firstStore] = $this->storeWithProduct('Aeris Kettle');

        $secondOffer = $this->addOffer($product, 8_900);
        $this->reindex($product);

        $secondStore = Store::query()->findOrFail($secondOffer->store_id);

        // The same canonical product, legitimately in two shop windows —
        // and one card in each, not a per-offer duplicate.
        foreach ([$firstStore, $secondStore] as $store) {
            $this->get('/stores/'.$store->slug)
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->has('results.data', 1)
                    ->where('results.data.0.title', 'Aeris Kettle'));
        }
    }

    #[Test]
    public function a_suspended_offer_leaves_its_sellers_store(): void
    {
        ['product' => $product, 'store' => $store] = $this->storeWithProduct('Aeris Kettle');

        Offer::query()->where('product_id', $product->id)->update(['status' => OfferStatus::Suspended->value]);
        $this->reindex($product);

        $this->get('/stores/'.$store->slug)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('results.data', 0));
    }

    #[Test]
    public function an_out_of_stock_product_keeps_its_page_and_says_so(): void
    {
        $product = $this->listedProduct('Aeris Kettle');
        $offer = Offer::query()->where('product_id', $product->id)->firstOrFail();

        $this->stockOffer($offer, 5);
        app(AdjustInventory::class)($offer, -5, InventoryMovementReason::Damaged, 'seller', 1);
        $this->reindex($product);

        /*
         * §32, the policy this codebase chose: a published product keeps
         * its page when it runs out. The URL, the ranking and the history
         * are worth more than the momentary stock level — but the page
         * says plainly that nobody can buy it, and the structured data
         * agrees.
         */
        $this->get('/products/'.$product->slug)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('inStock', false));
    }

    #[Test]
    public function an_out_of_stock_product_is_still_findable_and_marked(): void
    {
        $product = $this->listedProduct('Aeris Kettle');
        $offer = Offer::query()->where('product_id', $product->id)->firstOrFail();

        $this->stockOffer($offer, 3);
        app(AdjustInventory::class)($offer, -3, InventoryMovementReason::Damaged, 'seller', 1);
        $this->reindex($product);

        $this->get('/search?q=Aeris')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('results.data', 1)
                ->where('results.data.0.stockState', 'out_of_stock'));
    }

    #[Test]
    public function the_in_stock_filter_hides_what_cannot_be_bought(): void
    {
        $stocked = $this->listedProduct('Available Kettle');
        $empty = $this->listedProduct('Sold Out Kettle');

        $this->stockOffer(Offer::query()->where('product_id', $stocked->id)->firstOrFail(), 5);
        $this->reindex($stocked);
        $this->reindex($empty);

        $this->get('/search?in_stock=1')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('results.data', 1)
                ->where('results.data.0.title', 'Available Kettle'));
    }

    #[Test]
    public function a_card_and_the_product_page_quote_the_same_price(): void
    {
        $product = $this->listedProduct('Aeris Kettle', null, 12_000);
        $this->addOffer($product, 8_900);
        $this->addOffer($product, 15_000);
        $this->reindex($product);

        $card = $this->get('/search?q=Aeris')->assertOk();
        $cardPrice = $card->viewData('page')['props']['results']['data'][0]['price'] ?? null;

        $page = $this->get('/products/'.$product->slug)->assertOk();
        $featured = $page->viewData('page')['props']['offers'][0] ?? null;

        // The card shows the lowest eligible price; the product page
        // features the offer the ranking service picks. Both must be the
        // 89.00 seller, or a customer clicks a price and finds another.
        $this->assertNotNull($cardPrice);
        $this->assertNotNull($featured);
        $this->assertSame(8_900, $featured['priceMinor']);
        $this->assertStringContainsString('89.00', (string) $cardPrice);
    }

    #[Test]
    public function a_card_shows_a_range_when_sellers_genuinely_differ(): void
    {
        $product = $this->listedProduct('Aeris Kettle', null, 9_900);
        $this->addOffer($product, 19_900);
        $this->reindex($product);

        $this->get('/search?q=Aeris')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('results.data.0.hasPriceRange', true));
    }

    #[Test]
    public function a_product_nobody_lists_shows_no_price_rather_than_zero(): void
    {
        $product = Product::factory()->create([
            'title' => 'Unlisted Kettle',
            'status' => ProductStatus::Published->value,
            'published_at' => now(),
        ]);
        $this->reindex($product);

        $this->get('/search?q=Unlisted')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('results.data.0.price', ''));
    }

    #[Test]
    public function a_search_page_costs_the_same_whether_it_returns_one_result_or_twenty(): void
    {
        $this->listedProduct('Kettle Number One');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/search?q=Kettle')->assertOk();
        $one = count(DB::getQueryLog());
        DB::disableQueryLog();

        foreach (range(2, 20) as $index) {
            $this->listedProduct("Kettle Number {$index}");
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/search?q=Kettle')->assertOk();
        $twenty = count(DB::getQueryLog());
        DB::disableQueryLog();

        // The denormalised document earns its keep here: no media lookup,
        // no seller lookup and no inventory query per card.
        $this->assertSame(
            $one,
            $twenty,
            "20 results took {$twenty} queries against {$one} for a single result — an N+1.",
        );
    }

    #[Test]
    public function a_category_page_holds_its_query_count(): void
    {
        $category = Category::factory()->create(['name' => 'Kettles']);

        foreach (range(1, 12) as $index) {
            $this->listedProduct("Kettle {$index}", $category);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/categories/'.$category->slug)->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(
            25,
            $queries,
            "The category page ran {$queries} queries; something is loading per card.",
        );
    }

    #[Test]
    public function a_store_page_holds_its_query_count(): void
    {
        ['store' => $store, 'seller' => $seller] = $this->storeWithProduct('Kettle One');

        foreach (range(2, 12) as $index) {
            $product = $this->listedProduct("Kettle {$index}");
            $this->addOffer($product, 9_900, $seller, $store);
            $this->reindex($product);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/stores/'.$store->slug)->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(25, $queries, "The store page ran {$queries} queries.");
    }

    /** @return array{product: Product, store: Store, seller: SellerAccount} */
    private function storeWithProduct(string $title): array
    {
        $seller = SellerAccount::factory()->create(['status' => SellerStatus::Approved->value]);
        $store = Store::factory()->create(['seller_account_id' => $seller->id, 'is_open' => true]);

        $product = Product::factory()->create([
            'title' => $title,
            'status' => ProductStatus::Published->value,
            'published_at' => now(),
        ]);

        $this->addOffer($product, 9_900, $seller, $store);
        $this->reindex($product);

        return ['product' => $product, 'store' => $store, 'seller' => $seller];
    }

    private function listedProduct(string $title, ?Category $category = null, int $priceMinor = 9_900): Product
    {
        $product = Product::factory()->create([
            'title' => $title,
            'status' => ProductStatus::Published->value,
            'published_at' => now(),
            'category_id' => $category === null ? Category::factory()->create()->id : $category->id,
        ]);

        $this->addOffer($product, $priceMinor);
        $this->reindex($product);

        return $product->refresh();
    }

    private function addOffer(
        Product $product,
        int $priceMinor,
        ?SellerAccount $seller = null,
        ?Store $store = null,
    ): Offer {
        $seller ??= SellerAccount::factory()->create(['status' => SellerStatus::Approved->value]);
        $store ??= Store::factory()->create(['seller_account_id' => $seller->id, 'is_open' => true]);

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
