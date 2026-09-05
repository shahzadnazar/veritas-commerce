<?php

declare(strict_types=1);

namespace Tests\Feature\Reviews;

use App\Modules\Commission\Models\CommissionRule;
use App\Modules\Reviews\Actions\ModerateReview;
use App\Modules\Reviews\Actions\RecomputeRatingSummary;
use App\Modules\Reviews\Data\ReviewActor;
use App\Modules\Reviews\Models\ProductRatingSummary;
use App\Modules\Reviews\Models\ProductReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsCommerceFixtures;
use Tests\Feature\Fulfilment\BuildsFulfilableOrders;
use Tests\Feature\Orders\BuildsPlacedOrders;
use Tests\Feature\Payments\BuildsPayableOrders;
use Tests\TestCase;

/**
 * The second half of pre-UI property 3: a hidden or rejected review stops
 * affecting the STRUCTURED DATA, not only the stored summary.
 *
 * §67, §69 and §16 together. What a crawler is told and what a visitor
 * reads come from one number, so the only way they can disagree is if
 * somebody computes it twice — and nobody does.
 */
final class ReviewStructuredDataTest extends TestCase
{
    use BuildsCommerceFixtures;
    use BuildsFulfilableOrders;
    use BuildsPayableOrders;
    use BuildsPlacedOrders;
    use BuildsReviewableOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CommissionRule::factory()->create(['rate_percent' => '12.00']);
    }

    /** @return array<string, mixed> */
    private function pageProps(string $slug): array
    {
        $response = $this->get("/products/{$slug}");
        $response->assertOk();

        /** @var array<string, mixed> $props */
        $props = $response->viewData('page')['props'];

        return $props;
    }

    /**
     * The Product block, which every product page must emit.
     *
     * Fails the test rather than returning null: a page with no Product
     * schema at all is a different bug from a page whose rating is wrong,
     * and every assertion below is about the second.
     *
     * @return array<string, mixed>
     */
    private function productSchema(string $slug): array
    {
        $props = $this->pageProps($slug);

        /** @var array<int, array<string, mixed>> $blocks */
        $blocks = $props['structuredData'];

        foreach ($blocks as $block) {
            if (($block['@type'] ?? null) === 'Product') {
                return $block;
            }
        }

        $this->fail('The product page emitted no Product structured data.');
    }

    #[Test]
    public function a_product_nobody_has_reviewed_emits_no_aggregate_rating(): void
    {
        ['product' => $product] = $this->deliveredPurchase();

        // §69: no rating at all, rather than a confident 0.0.
        $this->assertArrayNotHasKey('aggregateRating', $this->productSchema($product->slug));

        $props = $this->pageProps($product->slug);
        $this->assertFalse($props['rating']['hasRating']);
        $this->assertNull($props['rating']['average']);
        $this->assertSame(0, $props['rating']['reviewCount']);
    }

    #[Test]
    public function a_reviewed_product_emits_the_rating_the_page_shows(): void
    {
        ['user' => $user, 'product' => $product] = $this->deliveredPurchase();

        $this->review($user, $product, rating: 4);

        $props = $this->pageProps($product->slug);
        $schema = $this->productSchema($product->slug);

        /** @var array<string, mixed> $rating */
        $rating = $schema['aggregateRating'];

        $this->assertSame('4.00', $rating['ratingValue']);
        $this->assertSame(1, $rating['reviewCount']);
        $this->assertSame(5, $rating['bestRating']);
        $this->assertSame(1, $rating['worstRating']);

        // The same number the visitor reads, from the same source.
        $this->assertSame(4.0, $props['rating']['average']);
        $this->assertSame('4.0', $props['rating']['averageLabel']);
        $this->assertSame(1, $props['rating']['reviewCount']);
    }

    #[Test]
    public function hiding_a_review_removes_it_from_the_markup_immediately(): void
    {
        ['user' => $user, 'product' => $product] = $this->deliveredPurchase();

        $review = $this->review($user, $product, rating: 5);

        /** @var array<string, mixed> $before */
        $before = $this->productSchema($product->slug)['aggregateRating'];

        $this->assertSame('5.00', $before['ratingValue']);

        app(ModerateReview::class)->hide($review, ReviewActor::admin(null, 'Moderator'), 'Checking.');

        $schema = $this->productSchema($product->slug);

        // No published reviews left, so nothing is claimed at all.
        $this->assertArrayNotHasKey('aggregateRating', $schema);
        $this->assertFalse($this->pageProps($product->slug)['rating']['hasRating']);
    }

    #[Test]
    public function a_rejected_review_is_absent_from_both_the_page_and_the_markup(): void
    {
        ['user' => $user, 'product' => $product] = $this->deliveredPurchase();

        $review = $this->review($user, $product, rating: 1, body: 'Deliberately abusive content here.');

        app(ModerateReview::class)->reject($review, ReviewActor::admin(null, 'Moderator'), 'Abusive.');

        $props = $this->pageProps($product->slug);

        $this->assertFalse($props['rating']['hasRating']);
        $this->assertArrayNotHasKey('aggregateRating', $this->productSchema($product->slug));

        // And the words themselves are nowhere in the response.
        $this->get("/products/{$product->slug}")
            ->assertDontSee('Deliberately abusive content here.');
    }

    #[Test]
    public function the_markup_average_matches_the_visible_average_to_the_stored_precision(): void
    {
        ['user' => $first, 'product' => $product] = $this->deliveredPurchase();

        // 5 and 4 average to 4.50; 4.5 on screen, 4.50 in the markup.
        $this->review($first, $product, rating: 5);

        ProductReview::factory()->rated(4)->create(['product_id' => $product->id]);
        app(RecomputeRatingSummary::class)((int) $product->id);

        $props = $this->pageProps($product->slug);

        /** @var array<string, mixed> $rating */
        $rating = $this->productSchema($product->slug)['aggregateRating'];

        $this->assertSame(4.5, $props['rating']['average']);
        $this->assertSame('4.5', $props['rating']['averageLabel']);
        $this->assertSame('4.50', $rating['ratingValue']);
        $this->assertSame(2, $rating['reviewCount']);

        // §16 stated as an assertion: one number, two renderings.
        $this->assertSame((float) $rating['ratingValue'], $props['rating']['average']);
        $this->assertSame($rating['reviewCount'], $props['rating']['reviewCount']);
    }

    #[Test]
    public function a_corrupt_summary_claiming_an_average_with_no_reviews_emits_nothing(): void
    {
        ['product' => $product] = $this->deliveredPurchase();

        // Written past the domain, as only a bug or a bad repair script
        // could. The markup must still refuse to make the claim.
        ProductRatingSummary::query()->create([
            'product_id' => $product->id,
            'published_review_count' => 0,
            'rating_sum' => 0,
            'rating_average' => '4.90',
        ]);

        $this->assertArrayNotHasKey('aggregateRating', $this->productSchema($product->slug));
    }
}
