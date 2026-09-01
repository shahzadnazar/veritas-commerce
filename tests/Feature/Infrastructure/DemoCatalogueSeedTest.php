<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Modules\Catalog\Models\Product;
use App\Modules\Offers\Queries\OfferEligibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The smoke fixture the Docker job depends on.
 *
 * It is deployment infrastructure, so it gets the same treatment as any
 * other code: if it stops producing a publicly resolvable product, the
 * suite says so here rather than the Docker job failing on a curl three
 * steps downstream.
 */
final class DemoCatalogueSeedTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_creates_a_product_that_actually_resolves_publicly(): void
    {
        $this->runArtisan('veritas:seed-demo-catalogue', ['--offers' => 2])->assertSuccessful()->run();

        $product = Product::query()->where('title', 'Aeris Cordless Kettle')->firstOrFail();

        $this->assertTrue($product->isPubliclyVisible());
        $this->get('/products/'.$product->slug)->assertOk();
    }

    #[Test]
    public function it_prints_the_slugs_the_smoke_script_reads(): void
    {
        $this->runArtisan('veritas:seed-demo-catalogue')
            ->expectsOutputToContain('product=')
            ->expectsOutputToContain('category=')
            ->expectsOutputToContain('store=')
            ->assertSuccessful()
            ->run();
    }

    #[Test]
    public function every_offer_it_creates_is_publicly_eligible(): void
    {
        $this->runArtisan('veritas:seed-demo-catalogue', ['--offers' => 3])->assertSuccessful()->run();

        $product = Product::query()->where('title', 'Aeris Cordless Kettle')->firstOrFail();

        // Three sellers, one canonical product, and every offer eligible —
        // otherwise the page renders but the smoke's price assertions are
        // testing an empty list.
        $eligible = app(OfferEligibility::class)->query()
            ->where('offers.product_id', $product->id)
            ->count();

        $this->assertSame(3, $eligible);
    }
}
