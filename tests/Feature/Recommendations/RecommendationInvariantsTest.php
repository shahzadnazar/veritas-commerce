<?php

declare(strict_types=1);

namespace Tests\Feature\Recommendations;

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Recommendations\Data\RecommendationRequest;
use App\Modules\Recommendations\Data\RecommendationSet;
use App\Modules\Recommendations\Enums\AssociationKind;
use App\Modules\Recommendations\Enums\RecommendationSlot;
use App\Modules\Recommendations\Queries\EligibleRecommendationProducts;
use App\Modules\Recommendations\RecommendationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Two more of the six properties the M8 brief requires proved before any
 * recommendation UI exists:
 *
 *   4. Recommendations never duplicate a canonical product because
 *      several sellers offer it.
 *   5. Recommendations never return a product that fails the catalogue's
 *      own public eligibility rule.
 *
 * Both are asserted against the service a page will actually call, not
 * against the gate in isolation — the point is that there is no path from
 * a controller to a product that skips the gate, and a test of the gate
 * alone would not show that.
 */
final class RecommendationInvariantsTest extends TestCase
{
    use BuildsRecommendationFixtures;
    use RefreshDatabase;

    // ---------------------------------------------------------------
    // Property 4: one canonical product, however many sellers list it.
    // ---------------------------------------------------------------

    #[Test]
    public function a_product_offered_by_five_sellers_appears_once(): void
    {
        $category = Category::factory()->create();
        $anchor = $this->listedProduct('Anchor kettle', category: $category);
        $rival = $this->listedProduct('Rival kettle', category: $category);

        // Four more sellers list the same canonical product.
        foreach (range(1, 4) as $extra) {
            $this->offerFor($rival['product'], priceMinor: 9_000 + $extra);
        }

        $this->reindex($rival['product']);

        $this->assertSame(
            5,
            DB::table('offers')->where('product_id', $rival['product']->id)->count(),
            'The fixture should have produced five competing offers.',
        );

        $set = $this->recommend(RecommendationSlot::SimilarProducts, $anchor['product']);

        $this->assertSame(
            [(int) $rival['product']->id],
            $set->productIds(),
            'Five sellers offering one product must produce one recommendation, not five.',
        );
    }

    #[Test]
    public function no_shelf_can_ever_contain_a_repeated_product(): void
    {
        $category = Category::factory()->create();
        $anchor = $this->listedProduct('Anchor', category: $category);
        $partner = $this->listedProduct('Partner', category: $category);

        // The same product, reachable through two different strategies:
        // an explicit association AND the same category.
        $this->associate($anchor['product'], $partner['product'], AssociationKind::BoughtTogether, support: 9);

        $set = $this->recommend(RecommendationSlot::BoughtTogether, $anchor['product']);

        $ids = $set->productIds();

        $this->assertSame(
            $ids,
            array_values(array_unique($ids)),
            'A product surfaced by two strategies must still occupy one place on the shelf.',
        );
        $this->assertSame([(int) $partner['product']->id], $ids);
    }

    #[Test]
    public function a_recommendation_carries_no_seller_or_offer_identity(): void
    {
        $category = Category::factory()->create();
        $anchor = $this->listedProduct('Anchor', category: $category);
        $rival = $this->listedProduct('Rival', category: $category);
        $this->offerFor($rival['product'], priceMinor: 8_000);
        $this->reindex($rival['product']);

        $set = $this->recommend(RecommendationSlot::SimilarProducts, $anchor['product']);
        $payload = $set->toArray();

        $this->assertNotEmpty($payload['products']);

        foreach ($payload['products'] as $product) {
            $keys = array_keys($product);

            $this->assertNotContains('offerId', $keys);
            $this->assertNotContains('sellerId', $keys);
            $this->assertNotContains('sellerAccountId', $keys);
            $this->assertArrayHasKey('productId', $product);
        }
    }

    // ---------------------------------------------------------------
    // Property 5: public catalogue eligibility, every time.
    // ---------------------------------------------------------------

    #[Test]
    public function an_unpublished_product_is_never_recommended(): void
    {
        $category = Category::factory()->create();
        $anchor = $this->listedProduct('Anchor', category: $category);
        $draft = $this->listedProduct('Withdrawn', category: $category);

        $this->assertContains(
            (int) $draft['product']->id,
            $this->recommend(RecommendationSlot::SimilarProducts, $anchor['product'])->productIds(),
            'The fixture must be recommendable before it is withdrawn, or the test proves nothing.',
        );

        $draft['product']->update(['status' => ProductStatus::Draft->value]);
        $this->reindex($draft['product']);

        $this->assertNotContains(
            (int) $draft['product']->id,
            $this->recommend(RecommendationSlot::SimilarProducts, $anchor['product'])->productIds(),
        );
    }

    #[Test]
    public function a_merged_product_is_never_recommended(): void
    {
        $category = Category::factory()->create();
        $anchor = $this->listedProduct('Anchor', category: $category);
        $survivor = $this->listedProduct('Survivor', category: $category);
        $duplicate = $this->listedProduct('Duplicate', category: $category);

        // Not fillable, by design — a merge is an action, not an edit.
        $duplicate['product']->forceFill([
            'merged_into_product_id' => $survivor['product']->id,
            'merged_at' => now(),
        ])->save();
        $this->reindex($duplicate['product']);

        $ids = $this->recommend(RecommendationSlot::SimilarProducts, $anchor['product'])->productIds();

        $this->assertNotContains((int) $duplicate['product']->id, $ids);
        $this->assertContains((int) $survivor['product']->id, $ids);
    }

    /**
     * The reason the gate reads the live products row and not only the
     * index: an index is eventually consistent, and "eventually" is not
     * good enough for "may this be shown".
     */
    #[Test]
    public function a_stale_index_cannot_leak_an_unpublished_product(): void
    {
        $category = Category::factory()->create();
        $anchor = $this->listedProduct('Anchor', category: $category);
        $withdrawn = $this->listedProduct('Withdrawn', category: $category);

        // Unpublished in the catalogue, but the index has not caught up —
        // its document still says the product is public.
        $withdrawn['product']->update(['status' => ProductStatus::Archived->value]);

        $this->assertTrue(
            (bool) DB::table('product_search_documents')
                ->where('product_id', $withdrawn['product']->id)
                ->value('is_public'),
            'This test requires a deliberately stale document.',
        );

        $this->assertNotContains(
            (int) $withdrawn['product']->id,
            $this->recommend(RecommendationSlot::SimilarProducts, $anchor['product'])->productIds(),
        );
    }

    #[Test]
    public function an_association_to_an_ineligible_product_yields_nothing(): void
    {
        $anchor = $this->listedProduct('Anchor');
        $partner = $this->listedProduct('Partner');

        $this->associate($anchor['product'], $partner['product'], AssociationKind::BoughtTogether, support: 20);

        $this->assertSame(
            [(int) $partner['product']->id],
            $this->recommend(RecommendationSlot::BoughtTogether, $anchor['product'])->productIds(),
        );

        $partner['product']->update(['status' => ProductStatus::Draft->value]);
        $this->reindex($partner['product']);

        $this->assertSame(
            [],
            $this->recommend(RecommendationSlot::BoughtTogether, $anchor['product'])->productIds(),
            'A strong association to a withdrawn product is still not a recommendation.',
        );
    }

    #[Test]
    public function every_strategy_chain_passes_through_the_same_gate(): void
    {
        $category = Category::factory()->create();
        $anchor = $this->listedProduct('Anchor', category: $category);
        $hidden = $this->listedProduct('Hidden', category: $category);

        // Reachable by association, by popularity and by recency.
        $this->associate($anchor['product'], $hidden['product'], AssociationKind::BoughtTogether, support: 9);
        $this->associate($anchor['product'], $hidden['product'], AssociationKind::ViewedTogether, support: 9);
        $this->score($hidden['product'], windowDays: 7, score: 5_000);
        $this->score($hidden['product'], windowDays: 30, score: 5_000);

        $hidden['product']->update(['status' => ProductStatus::Draft->value]);
        $this->reindex($hidden['product']);

        foreach (RecommendationSlot::cases() as $slot) {
            $set = $this->recommend($slot, $slot->requiresAnchor() ? $anchor['product'] : null);

            $this->assertNotContains(
                (int) $hidden['product']->id,
                $set->productIds(),
                "The {$slot->value} shelf leaked an unpublished product.",
            );
        }
    }

    #[Test]
    public function the_gate_filters_ids_the_same_way_it_builds_cards(): void
    {
        $live = $this->listedProduct('Live');
        $dead = $this->listedProduct('Dead');
        $dead['product']->update(['status' => ProductStatus::Draft->value]);
        $this->reindex($dead['product']);

        $gate = app(EligibleRecommendationProducts::class);
        $candidates = [(int) $dead['product']->id, (int) $live['product']->id];

        $this->assertSame([(int) $live['product']->id], $gate->filterIds($candidates));
        $this->assertSame(
            [(int) $live['product']->id],
            array_map(
                static fn ($product): int => $product->productId,
                $gate($candidates, 10),
            ),
        );
    }

    #[Test]
    public function a_product_nobody_offers_is_not_recommended(): void
    {
        $category = Category::factory()->create();
        $anchor = $this->listedProduct('Anchor', category: $category);

        $orphan = Product::factory()->create([
            'title' => 'Nobody sells this',
            'status' => ProductStatus::Published->value,
            'published_at' => now(),
            'category_id' => $category->id,
        ]);
        $this->reindex($orphan);

        $this->assertNotContains(
            (int) $orphan->id,
            $this->recommend(RecommendationSlot::SimilarProducts, $anchor['product'])->productIds(),
            'A shelf must not offer a product with no price and no seller.',
        );
    }

    #[Test]
    public function an_unindexed_product_is_not_recommended(): void
    {
        $category = Category::factory()->create();
        $anchor = $this->listedProduct('Anchor', category: $category);
        $fresh = $this->listedProduct('Fresh', category: $category);

        DB::table('product_search_documents')->where('product_id', $fresh['product']->id)->delete();

        $this->assertNotContains(
            (int) $fresh['product']->id,
            $this->recommend(RecommendationSlot::SimilarProducts, $anchor['product'])->productIds(),
        );
    }

    // ---------------------------------------------------------------
    // Shelf behaviour the properties depend on.
    // ---------------------------------------------------------------

    #[Test]
    public function a_product_is_never_recommended_alongside_itself(): void
    {
        $category = Category::factory()->create();
        $anchor = $this->listedProduct('Anchor', category: $category);
        $this->listedProduct('Other', category: $category);

        // Even an explicit self-association — which the CHECK constraint
        // forbids at the database level — could not reach the shelf.
        $set = $this->recommend(RecommendationSlot::SimilarProducts, $anchor['product']);

        $this->assertNotContains((int) $anchor['product']->id, $set->productIds());
        $this->assertNotEmpty($set->productIds());
    }

    #[Test]
    public function an_anchored_shelf_without_an_anchor_returns_nothing(): void
    {
        $this->listedProduct('Something');

        foreach (RecommendationSlot::cases() as $slot) {
            if (! $slot->requiresAnchor()) {
                continue;
            }

            $this->assertTrue(
                $this->recommend($slot, null)->isEmpty(),
                "The {$slot->value} shelf invented recommendations with nothing to anchor on.",
            );
        }
    }

    #[Test]
    public function the_same_request_twice_produces_the_same_order(): void
    {
        $category = Category::factory()->create();
        $anchor = $this->listedProduct('Anchor', category: $category);

        foreach (range(1, 6) as $index) {
            $this->listedProduct("Rival {$index}", priceMinor: 9_000 + $index * 100, category: $category);
        }

        $first = $this->recommend(RecommendationSlot::SimilarProducts, $anchor['product'])->productIds();
        $second = $this->recommend(RecommendationSlot::SimilarProducts, $anchor['product'])->productIds();

        $this->assertNotEmpty($first);
        $this->assertSame($first, $second, 'A shelf that reshuffles on refresh looks broken.');
    }

    #[Test]
    public function an_association_below_its_support_threshold_is_ignored(): void
    {
        $anchor = $this->listedProduct('Anchor');
        $weak = $this->listedProduct('Weak');

        $threshold = AssociationKind::BoughtTogether->minimumSupport();
        $this->associate($anchor['product'], $weak['product'], AssociationKind::BoughtTogether, $threshold - 1);

        $this->assertSame(
            [],
            $this->recommend(RecommendationSlot::BoughtTogether, $anchor['product'])->productIds(),
            'One shared order is a coincidence, not a pattern.',
        );

        $this->associate($anchor['product'], $weak['product'], AssociationKind::BoughtTogether, $threshold);

        // The ranking is cached for the configured window, so a shelf does
        // not change the instant a projection does. Expiring it here is
        // what a caller would experience a few minutes later.
        Cache::flush();

        $this->assertSame(
            [(int) $weak['product']->id],
            $this->recommend(RecommendationSlot::BoughtTogether, $anchor['product'])->productIds(),
        );
    }

    #[Test]
    public function an_empty_best_strategy_falls_through_to_the_next(): void
    {
        $category = Category::factory()->create();
        $anchor = $this->listedProduct('Anchor', category: $category);
        $neighbour = $this->listedProduct('Neighbour', category: $category);

        $this->assertSame(
            0,
            DB::table('product_associations')->count(),
            'This test needs a marketplace with no co-occurrence data at all.',
        );

        $set = $this->recommend(RecommendationSlot::AlsoViewed, $anchor['product']);

        $this->assertSame([(int) $neighbour['product']->id], $set->productIds());
        $this->assertTrue($set->usedFallback, 'The set should admit it was filled by a fallback.');
        $this->assertContains('similar_product', $set->strategies);
    }

    #[Test]
    public function a_shelf_never_repeats_what_an_earlier_shelf_showed(): void
    {
        $category = Category::factory()->create();
        $anchor = $this->listedProduct('Anchor', category: $category);
        $partner = $this->listedProduct('Partner', category: $category);
        $spare = $this->listedProduct('Spare', category: $category);

        $this->associate($anchor['product'], $partner['product'], AssociationKind::BoughtTogether, support: 9);

        $shelves = app(RecommendationService::class)->shelves(
            [RecommendationSlot::BoughtTogether, RecommendationSlot::SimilarProducts],
            new RecommendationRequest(
                slot: RecommendationSlot::BoughtTogether,
                anchorProductId: (int) $anchor['product']->id,
            ),
        );

        $bought = array_column($shelves[RecommendationSlot::BoughtTogether->value]['products'], 'productId');
        $similar = array_column($shelves[RecommendationSlot::SimilarProducts->value]['products'], 'productId');

        $this->assertSame([(int) $partner['product']->id], $bought);
        $this->assertSame([(int) $spare['product']->id], $similar);
        $this->assertSame([], array_intersect($bought, $similar));
    }

    #[Test]
    public function a_similar_shelf_prefers_the_same_brand_and_price_band(): void
    {
        $category = Category::factory()->create();
        $brand = Brand::factory()->create();

        $anchor = $this->listedProduct('Anchor', priceMinor: 10_000, category: $category, brand: $brand);
        $comparable = $this->listedProduct('Comparable', priceMinor: 11_000, category: $category, brand: $brand);
        $expensive = $this->listedProduct('Expensive', priceMinor: 90_000, category: $category);

        $ids = $this->recommend(RecommendationSlot::SimilarProducts, $anchor['product'])->productIds();

        $this->assertSame(
            [(int) $comparable['product']->id, (int) $expensive['product']->id],
            $ids,
            'A product at nine times the price is not the closest comparison.',
        );
    }

    #[Test]
    public function a_personal_shelf_is_never_cached_across_visitors(): void
    {
        $category = Category::factory()->create();
        $mine = $this->listedProduct('Mine', category: $category);
        $theirs = $this->listedProduct('Theirs', category: $category);

        $this->viewed($mine['product'], session: 'session-one');
        $this->viewed($theirs['product'], session: 'session-two');

        $service = app(RecommendationService::class);

        $first = $service->for(new RecommendationRequest(
            slot: RecommendationSlot::RecentlyViewed,
            anonymousSessionId: 'session-one',
        ))->productIds();

        $second = $service->for(new RecommendationRequest(
            slot: RecommendationSlot::RecentlyViewed,
            anonymousSessionId: 'session-two',
        ))->productIds();

        $this->assertSame([(int) $mine['product']->id], $first);
        $this->assertSame([(int) $theirs['product']->id], $second);
    }

    // ---------------------------------------------------------------
    // Helpers.
    // ---------------------------------------------------------------

    private function recommend(
        RecommendationSlot $slot,
        ?Product $anchor,
        int $limit = 12,
    ): RecommendationSet {
        return app(RecommendationService::class)->for(new RecommendationRequest(
            slot: $slot,
            anchorProductId: $anchor === null ? null : (int) $anchor->id,
            limit: $limit,
        ));
    }

    private function associate(
        Product $product,
        Product $associated,
        AssociationKind $kind,
        int $support,
    ): void {
        DB::table('product_associations')->updateOrInsert(
            [
                'product_id' => $product->id,
                'associated_product_id' => $associated->id,
                'kind' => $kind->value,
            ],
            ['support' => $support, 'score' => $support, 'computed_at' => now()],
        );
    }

    private function score(Product $product, int $windowDays, int $score): void
    {
        DB::table('product_popularity_scores')->updateOrInsert(
            ['product_id' => $product->id, 'window_days' => $windowDays],
            ['score' => $score, 'computed_at' => now()],
        );
    }
}
