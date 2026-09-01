<?php

declare(strict_types=1);

namespace Tests\Feature\Discovery;

use App\Modules\Catalog\Enums\AttributeType;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Attribute;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Queries\SearchQueryFactory;
use App\Modules\Offers\Enums\OfferStatus;
use App\Modules\Offers\Models\Offer;
use App\Modules\Search\Data\SearchQuery;
use App\Modules\Search\Enums\SortOption;
use App\Modules\Sellers\Enums\SellerStatus;
use App\Modules\Sellers\Models\SellerAccount;
use App\Modules\Stores\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Filter parameters are untrusted input.
 *
 * §23's answer is not to escape strings but to resolve every parameter
 * against the real catalogue before it goes near a query — so these check
 * both halves: that hostile input is discarded, and that the discarding is
 * silent, because an attacker probing parameter names should see exactly
 * what a customer following a stale bookmark sees.
 */
final class SearchSecurityTest extends TestCase
{
    use BuildsCatalogue;
    use RefreshDatabase;

    #[Test]
    public function a_sql_fragment_in_a_filter_is_discarded_rather_than_executed(): void
    {
        $this->listedProduct('Aeris Kettle');

        $hostile = [
            'brand' => ['1; drop table products --'],
            'condition' => ["' or 1=1 --"],
            'sort' => 'price_asc; delete from products',
            'min_price' => '0 union select null',
            'category' => "kettles' or '1'='1",
            'attributes' => ['storage); drop table products; --' => ['256']],
        ];

        $this->get('/search?'.http_build_query(['q' => 'Aeris'] + $hostile))->assertOk();

        // The tables are still there, and the query still worked.
        $this->assertDatabaseCount('products', 1);
        $this->assertSame(1, Product::query()->count());
    }

    #[Test]
    public function an_unknown_brand_id_filters_nothing_rather_than_erroring(): void
    {
        $this->listedProduct('Aeris Kettle');

        // A stale bookmark from before a brand was deleted.
        $query = $this->factory(['brand' => ['999999']]);

        $this->assertSame([], $query->brandIds);
        $this->get('/search?q=Aeris&brand[]=999999')->assertOk();
    }

    #[Test]
    public function an_inactive_brand_cannot_be_filtered_on(): void
    {
        $active = Brand::query()->create([
            'name' => 'Aeris', 'normalised_name' => 'aeris', 'slug' => 'aeris', 'is_active' => true,
        ]);
        $retired = Brand::query()->create([
            'name' => 'Retired', 'normalised_name' => 'retired', 'slug' => 'retired', 'is_active' => false,
        ]);

        $query = $this->factory(['brand' => [(string) $active->id, (string) $retired->id]]);

        $this->assertSame([$active->id], $query->brandIds);
    }

    #[Test]
    public function a_hidden_category_cannot_be_reached_by_naming_it_as_a_filter(): void
    {
        $hidden = Category::factory()->create(['name' => 'Retired lines', 'is_visible' => false]);

        $query = $this->factory(['category' => $hidden->slug]);

        // Hiding a category has to mean hiding it, including from someone
        // who knows its slug.
        $this->assertNull($query->categoryId);
    }

    #[Test]
    public function an_attribute_that_is_not_filterable_is_rejected(): void
    {
        $category = Category::factory()->create(['name' => 'Phones']);

        $filterable = Attribute::factory()->ofType(AttributeType::Integer)->create([
            'code' => 'storage', 'name' => 'Storage', 'is_filterable' => true,
        ]);
        $internal = Attribute::factory()->ofType(AttributeType::Text)->create([
            'code' => 'supplier_note', 'name' => 'Supplier note', 'is_filterable' => false,
        ]);

        $category->attributes()->attach([$filterable->id, $internal->id]);

        $query = $this->factory([
            'category' => $category->slug,
            'attributes' => ['storage' => ['256'], 'supplier_note' => ['anything']],
        ]);

        $this->assertArrayHasKey('storage', $query->attributes);
        $this->assertArrayNotHasKey('supplier_note', $query->attributes);
    }

    #[Test]
    public function an_attribute_belonging_to_another_category_is_rejected(): void
    {
        $phones = Category::factory()->create(['name' => 'Phones']);
        $shoes = Category::factory()->create(['name' => 'Shoes']);

        $shoeSize = Attribute::factory()->ofType(AttributeType::Integer)->create([
            'code' => 'shoe_size', 'name' => 'Size', 'is_filterable' => true,
        ]);
        $shoes->attributes()->attach($shoeSize->id);

        // Filtering phones by shoe size is not a query anyone meant.
        $query = $this->factory([
            'category' => $phones->slug,
            'attributes' => ['shoe_size' => ['44']],
        ]);

        $this->assertSame([], $query->attributes);
    }

    #[Test]
    public function an_unknown_sort_falls_back_to_relevance(): void
    {
        $this->assertSame(SortOption::Relevance, SortOption::fromRequest('drop table products'));
        $this->assertSame(SortOption::Relevance, SortOption::fromRequest(null));
        $this->assertSame(SortOption::PriceAscending, SortOption::fromRequest('price_asc'));
    }

    #[Test]
    public function a_nonsense_price_is_ignored_and_a_huge_one_is_capped(): void
    {
        $this->assertNull($this->factory(['min_price' => 'abc'])->minPriceMinor);
        $this->assertNull($this->factory(['min_price' => '-50'])->minPriceMinor);
        $this->assertSame(100_000_000, $this->factory(['min_price' => '99999999999'])->minPriceMinor);
    }

    #[Test]
    public function an_absurd_page_number_does_not_break_the_page(): void
    {
        $this->listedProduct('Aeris Kettle');

        $this->assertSame(1, $this->factory(['page' => '-5'])->page);
        $this->get('/search?q=Aeris&page=999999')->assertOk();
    }

    #[Test]
    public function autocomplete_never_suggests_something_the_public_cannot_see(): void
    {
        $draft = $this->listedProduct('Aeris Secret Prototype');
        $draft->forceFill(['status' => ProductStatus::PendingReview->value])->save();
        $this->reindex($draft);

        $this->listedProduct('Aeris Cordless Kettle');

        $response = $this->getJson('/search/suggestions?q=aeris')->assertOk();

        $labels = array_column($response->json('suggestions'), 'label');

        $this->assertContains('Aeris Cordless Kettle', $labels);
        $this->assertNotContains('Aeris Secret Prototype', $labels);
    }

    #[Test]
    public function autocomplete_refuses_a_query_too_short_to_be_useful(): void
    {
        $this->listedProduct('Aeris Kettle');

        // One character would match most of the catalogue and cost a scan
        // on every keystroke.
        $this->getJson('/search/suggestions?q=a')->assertOk()->assertExactJson(['suggestions' => []]);
    }

    #[Test]
    public function autocomplete_returns_a_bounded_number_of_rows(): void
    {
        foreach (range(1, 25) as $index) {
            $this->listedProduct("Aeris Kettle Model {$index}");
        }

        $response = $this->getJson('/search/suggestions?q=aeris')->assertOk();

        $this->assertLessThanOrEqual(8, count($response->json('suggestions')));
    }

    #[Test]
    public function the_search_page_is_never_indexable(): void
    {
        $this->listedProduct('Aeris Kettle');

        // Both signals: the header survives a misconfigured SSR process,
        // which is exactly when an accidental index would happen.
        $this->get('/search?q=Aeris')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, follow')
            ->assertInertia(fn ($page) => $page->where('seo.robots', 'noindex, follow'));
    }

    /** @param  array<string, mixed>  $parameters */
    private function factory(array $parameters): SearchQuery
    {
        return app(SearchQueryFactory::class)(Request::create('/search', 'GET', $parameters));
    }

    private function listedProduct(string $title): Product
    {
        $product = Product::factory()->create([
            'title' => $title,
            'status' => ProductStatus::Published->value,
            'published_at' => now(),
        ]);

        $seller = SellerAccount::factory()->create(['status' => SellerStatus::Approved->value]);
        $store = Store::factory()->create(['seller_account_id' => $seller->id, 'is_open' => true]);

        Offer::factory()->create([
            'seller_account_id' => $seller->id,
            'store_id' => $store->id,
            'product_id' => $product->id,
            'product_variant_id' => null,
            'status' => OfferStatus::Published->value,
        ]);

        $this->reindex($product);

        return $product->refresh();
    }
}
