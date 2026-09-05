<?php

declare(strict_types=1);

namespace Tests\Feature\Catalogue;

use App\Modules\Reviews\Models\ProductReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The smoke-test fixture has to reach the code the smoke test checks.
 *
 * CI seeds this catalogue and then asserts that the product page
 * server-renders. For a long time it seeded a product with no reviews, so
 * the rating histogram — the part that was actually broken — was skipped
 * entirely, and the assertion passed over a page that threw for every
 * real rated product.
 *
 * This pins the fixture: the seeded product carries published reviews at
 * more than one star, the page claims the rating, and the histogram has a
 * row per star. If someone drops the reviews from the seeder to make it
 * quicker, it fails here rather than silently in CI.
 */
final class DemoCatalogueFixtureTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_seeded_product_carries_a_rating_the_page_can_render(): void
    {
        $slug = $this->seedCatalogue();

        $this->assertSame(3, ProductReview::query()->count());

        $this->get('/products/'.$slug)
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $rating = $page->toArray()['props']['rating'];

                $this->assertTrue($rating['hasRating']);
                $this->assertSame(3, $rating['reviewCount']);

                // A row per star, highest first — the shape the component
                // iterates. The seeded ratings are 5, 4 and 5.
                $this->assertCount(5, $rating['distribution']);
                $this->assertSame(
                    [5, 4, 3, 2, 1],
                    array_map(static fn (array $row): int => $row['rating'], $rating['distribution']),
                );
                $this->assertSame(2, $rating['distribution'][0]['count']);
                $this->assertSame(1, $rating['distribution'][1]['count']);
            });
    }

    /**
     * More than one star is the point.
     *
     * A histogram whose reviews all sit in the same row would render
     * without ever exercising a second row, which is most of it.
     */
    #[Test]
    public function the_seeded_reviews_span_more_than_one_star(): void
    {
        $this->seedCatalogue();

        $this->assertGreaterThan(1, ProductReview::query()->distinct()->pluck('rating')->count());
    }

    private function seedCatalogue(): string
    {
        Artisan::call('veritas:seed-demo-catalogue', ['--offers' => 1, '--reviews' => 3]);

        foreach (explode("\n", Artisan::output()) as $line) {
            if (str_starts_with(trim($line), 'product=')) {
                return trim(explode('=', $line, 2)[1]);
            }
        }

        $this->fail('The seeder did not print a product slug.');
    }
}
