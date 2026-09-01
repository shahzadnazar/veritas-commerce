<?php

declare(strict_types=1);

namespace Tests\Feature\Discovery;

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Offers\Enums\OfferStatus;
use App\Modules\Offers\Models\Offer;
use App\Modules\Sellers\Enums\SellerStatus;
use App\Modules\Sellers\Models\SellerAccount;
use App\Modules\Stores\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What the marketplace tells crawlers to fetch.
 *
 * The rule throughout: only URLs that actually resolve. A sitemap listing
 * a suspended product is telling a crawler to fetch a 404, which is the
 * fastest way to teach it to trust the file less.
 */
final class SitemapTest extends TestCase
{
    use BuildsCatalogue;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    #[Test]
    public function the_index_points_at_one_sitemap_per_kind(): void
    {
        $response = $this->get('/sitemap.xml');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/xml; charset=UTF-8');

        $body = $response->getContent() ?: '';

        $this->assertStringContainsString('products-sitemap.xml', $body);
        $this->assertStringContainsString('categories-sitemap.xml', $body);
        $this->assertStringContainsString('stores-sitemap.xml', $body);
    }

    #[Test]
    public function a_published_product_is_listed(): void
    {
        $product = $this->listedProduct('Aeris Kettle');

        $this->get('/products-sitemap.xml')
            ->assertOk()
            ->assertSee('/products/'.$product->slug, false);
    }

    #[Test]
    public function a_suspended_product_is_not_listed(): void
    {
        $product = $this->listedProduct('Recalled Kettle');
        $product->forceFill(['status' => ProductStatus::Suspended->value])->save();
        $this->reindex($product);

        Cache::flush();

        $this->get('/products-sitemap.xml')
            ->assertOk()
            ->assertDontSee('/products/'.$product->slug, false);
    }

    #[Test]
    public function an_unpublished_product_is_not_listed(): void
    {
        $product = $this->listedProduct('Draft Kettle');
        $product->forceFill(['status' => ProductStatus::PendingReview->value])->save();
        $this->reindex($product);

        Cache::flush();

        $this->get('/products-sitemap.xml')
            ->assertOk()
            ->assertDontSee('/products/'.$product->slug, false);
    }

    #[Test]
    public function a_visible_category_is_listed_and_a_hidden_one_is_not(): void
    {
        $visible = Category::factory()->create(['name' => 'Kettles', 'is_visible' => true]);
        $hidden = Category::factory()->create(['name' => 'Retired', 'is_visible' => false]);

        $response = $this->get('/categories-sitemap.xml')->assertOk();

        $response->assertSee('/categories/'.$visible->slug, false);
        $response->assertDontSee('/categories/'.$hidden->slug, false);
    }

    #[Test]
    public function an_open_store_is_listed_and_a_closed_one_is_not(): void
    {
        $seller = SellerAccount::factory()->create(['status' => SellerStatus::Approved->value]);
        $open = Store::factory()->create(['seller_account_id' => $seller->id, 'is_open' => true]);
        $closed = Store::factory()->create([
            'seller_account_id' => SellerAccount::factory()->create()->id,
            'is_open' => false,
        ]);

        $response = $this->get('/stores-sitemap.xml')->assertOk();

        $response->assertSee('/stores/'.$open->slug, false);
        $response->assertDontSee('/stores/'.$closed->slug, false);
    }

    #[Test]
    public function a_suspended_sellers_store_is_not_listed(): void
    {
        $seller = SellerAccount::factory()->create(['status' => SellerStatus::Suspended->value]);
        $store = Store::factory()->create(['seller_account_id' => $seller->id, 'is_open' => true]);

        // Open, but its owner is suspended: two different conditions with
        // the same consequence for a crawler.
        $this->get('/stores-sitemap.xml')
            ->assertOk()
            ->assertDontSee('/stores/'.$store->slug, false);
    }

    #[Test]
    public function no_sitemap_contains_a_search_admin_or_portal_url(): void
    {
        $this->listedProduct('Aeris Kettle');

        foreach (['/sitemap.xml', '/products-sitemap.xml', '/categories-sitemap.xml', '/stores-sitemap.xml'] as $url) {
            $body = $this->get($url)->assertOk()->getContent() ?: '';

            $this->assertStringNotContainsString('/search', $body);
            $this->assertStringNotContainsString('/admin', $body);
            $this->assertStringNotContainsString('/seller', $body);
        }
    }

    #[Test]
    public function robots_points_at_the_sitemap_and_shuts_the_crawler_out_of_the_portals(): void
    {
        $response = $this->get('/robots.txt');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');

        $body = $response->getContent() ?: '';

        $this->assertStringContainsString('Sitemap: ', $body);
        $this->assertStringContainsString('/sitemap.xml', $body);
        $this->assertStringContainsString('Disallow: /admin', $body);
        $this->assertStringContainsString('Disallow: /seller', $body);
        $this->assertStringContainsString('Disallow: /search', $body);
    }

    #[Test]
    public function every_product_url_in_the_sitemap_actually_resolves(): void
    {
        $product = $this->listedProduct('Aeris Kettle');
        $this->listedProduct('Bialetti Moka');

        $body = $this->get('/products-sitemap.xml')->assertOk()->getContent() ?: '';

        preg_match_all('#<loc>[^<]*/products/([^<]+)</loc>#', $body, $matches);

        $this->assertNotEmpty($matches[1]);

        // The property that makes a sitemap worth publishing at all.
        foreach ($matches[1] as $slug) {
            $this->get('/products/'.$slug)->assertOk();
        }

        $this->assertContains($product->slug, $matches[1]);
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
        Cache::flush();

        return $product->refresh();
    }
}
