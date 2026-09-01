<?php

declare(strict_types=1);

namespace Tests\Feature\Catalogue;

use App\Modules\Catalog\Models\Product;
use App\Modules\Offers\Enums\OfferStatus;
use App\Modules\Offers\Models\Offer;
use App\Modules\Sellers\Models\SellerAccount;
use App\Modules\Stores\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The product page as a crawler receives it.
 *
 * Server-side rendering is where the SEO work either lands or does not, so
 * this runs the real SSR bundle in a real node process rather than
 * asserting against the client-side markup. Two titles in one document is
 * a silent failure — browsers show the first and say nothing — which is
 * exactly the kind of defect that survives every unit test.
 */
final class ProductPageSsrTest extends TestCase
{
    use RefreshDatabase;

    /** @var resource|null */
    private $server = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->startSsrServer();

        config([
            'inertia.ssr.enabled' => true,
            'inertia.ssr.url' => 'http://127.0.0.1:13714',
        ]);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
            $this->server = null;
        }

        parent::tearDown();
    }

    #[Test]
    public function the_rendered_page_emits_exactly_one_title(): void
    {
        $product = $this->listedProduct();

        $html = (string) $this->get('/products/'.$product->slug)->assertOk()->getContent();

        // One, not zero: without SSR the shell supplies a fallback, and
        // with SSR the page supplies its own. Never both.
        $this->assertSame(1, substr_count($html, '<title'), 'Expected exactly one <title> in the SSR output.');
        $this->assertStringContainsString($product->title, $html);
    }

    #[Test]
    public function the_rendered_page_carries_one_canonical_link(): void
    {
        $product = $this->listedProduct();

        $html = (string) $this->get('/products/'.$product->slug)->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'rel="canonical"'));
        $this->assertStringContainsString('/products/'.$product->slug, $html);
    }

    #[Test]
    public function the_body_is_complete_html_before_any_javascript_runs(): void
    {
        $product = $this->listedProduct();

        $html = (string) $this->get('/products/'.$product->slug)->assertOk()->getContent();

        // The whole point of SSR here: a crawler that runs no scripts
        // still sees the product, its price and its sellers.
        $this->assertStringContainsString($product->title, $html);
        $this->assertStringContainsString('application/ld+json', $html);
    }

    private function listedProduct(): Product
    {
        $product = Product::factory()->create(['title' => 'Aeris Cordless Kettle 1.2L']);

        $seller = SellerAccount::factory()->create();
        $store = Store::factory()->create(['seller_account_id' => $seller->id, 'is_open' => true]);

        Offer::factory()->create([
            'seller_account_id' => $seller->id,
            'store_id' => $store->id,
            'product_id' => $product->id,
            'product_variant_id' => null,
            'price_minor' => 9_900,
            'status' => OfferStatus::Published->value,
        ]);

        return $product;
    }

    /**
     * Runs the built SSR bundle, the same one production serves.
     *
     * Deliberately not skipped when the bundle is missing: a green suite
     * that quietly stopped checking the rendered output is worse than a
     * red one that says the build has not been run.
     */
    private function startSsrServer(): void
    {
        $bundle = base_path('bootstrap/ssr/ssr.js');

        if (! is_file($bundle)) {
            throw new RuntimeException("SSR bundle missing at {$bundle}. Run: npm run build:ssr");
        }

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(['node', $bundle], $descriptors, $pipes);

        if (! is_resource($process)) {
            throw new RuntimeException('Could not start node for the SSR bundle.');
        }

        $this->server = $process;

        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }

        // Up to five seconds, checked every 100ms — the bundle is small
        // and normally answers well inside the first second.
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $socket = @fsockopen('127.0.0.1', 13714, $errno, $error, 0.2);

            if (is_resource($socket)) {
                fclose($socket);

                return;
            }

            usleep(100_000);
        }

        throw new RuntimeException('The SSR server did not start listening on 13714.');
    }
}
