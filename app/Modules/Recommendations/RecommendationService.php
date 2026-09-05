<?php

declare(strict_types=1);

namespace App\Modules\Recommendations;

use App\Modules\Recommendations\Contracts\RecommendationStrategy;
use App\Modules\Recommendations\Data\RecommendationRequest;
use App\Modules\Recommendations\Data\RecommendationSet;
use App\Modules\Recommendations\Data\RecommendedProduct;
use App\Modules\Recommendations\Enums\RecommendationSlot;
use App\Modules\Recommendations\Queries\EligibleRecommendationProducts;
use App\Modules\Recommendations\Strategies\BoughtTogetherStrategy;
use App\Modules\Recommendations\Strategies\NewArrivalsStrategy;
use App\Modules\Recommendations\Strategies\PersonalAffinityStrategy;
use App\Modules\Recommendations\Strategies\PopularInCategoryStrategy;
use App\Modules\Recommendations\Strategies\RecentlyViewedStrategy;
use App\Modules\Recommendations\Strategies\SimilarProductStrategy;
use App\Modules\Recommendations\Strategies\TrendingStrategy;
use App\Modules\Recommendations\Strategies\ViewedTogetherStrategy;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Cache;

/**
 * The only way a page gets recommendations.
 *
 * §29: a controller asks for a slot and receives finished products. It
 * does not name a strategy, does not query interaction events, does not
 * know what "support" means and cannot accidentally show something
 * unpublished — because every path out of this class goes through
 * EligibleRecommendationProducts.
 *
 * The shape is a chain (§39). Each slot lists its strategies best-first;
 * the service runs them in order, accumulating candidate ids, and stops as
 * soon as enough *eligible* products have been found. Stopping on eligible
 * products rather than on candidates is the important part: a strategy
 * that returned twelve ids of which ten are unpublished has not filled the
 * shelf, and the next strategy still runs.
 *
 * Caching is per slot and per anchor, and personal slots are never cached
 * at all — one visitor's history must not become another's shelf. The
 * decision is read from the slot rather than passed in, so a new caller
 * cannot get it wrong.
 */
final class RecommendationService
{
    public function __construct(
        private readonly Container $container,
        private readonly EligibleRecommendationProducts $eligible,
    ) {}

    /**
     * The strategies each slot tries, in order.
     *
     * Read as a sentence: "for the product page's similar shelf, prefer
     * what people bought together, then what they viewed together, then
     * genuine catalogue similarity, then what is popular in the category,
     * then what is trending, and if the marketplace opened yesterday, what
     * is new."
     *
     * @return array<string, array<int, class-string<RecommendationStrategy>>>
     */
    public static function chains(): array
    {
        return [
            RecommendationSlot::SimilarProducts->value => [
                SimilarProductStrategy::class,
                PopularInCategoryStrategy::class,
                TrendingStrategy::class,
                NewArrivalsStrategy::class,
            ],
            RecommendationSlot::BoughtTogether->value => [
                BoughtTogetherStrategy::class,
                ViewedTogetherStrategy::class,
                PopularInCategoryStrategy::class,
            ],
            RecommendationSlot::AlsoViewed->value => [
                ViewedTogetherStrategy::class,
                SimilarProductStrategy::class,
                PopularInCategoryStrategy::class,
                TrendingStrategy::class,
            ],
            RecommendationSlot::RecentlyViewed->value => [
                RecentlyViewedStrategy::class,
            ],
            RecommendationSlot::Trending->value => [
                TrendingStrategy::class,
                NewArrivalsStrategy::class,
            ],
            RecommendationSlot::ForYou->value => [
                PersonalAffinityStrategy::class,
                TrendingStrategy::class,
                NewArrivalsStrategy::class,
            ],
            RecommendationSlot::CartAddons->value => [
                BoughtTogetherStrategy::class,
                ViewedTogetherStrategy::class,
                PopularInCategoryStrategy::class,
            ],
            RecommendationSlot::NewArrivals->value => [
                NewArrivalsStrategy::class,
            ],
        ];
    }

    public function for(RecommendationRequest $request): RecommendationSet
    {
        if ($request->slot->requiresAnchor() && $request->anchors() === []) {
            // A "similar products" shelf with nothing to be similar to is
            // a caller mistake, not a cold start. Returning empty is
            // honest; falling back to trending would hide the bug.
            return RecommendationSet::empty($request->slot);
        }

        $key = $this->cacheKey($request);
        $seconds = $this->cacheSeconds();

        if ($key === null || $seconds < 1) {
            return $this->build($request);
        }

        /** @var array{ids: array<int, int>, reasons: array<int, string>, strategies: array<int, string>, fallback: bool} $cached */
        $cached = Cache::remember(
            $key,
            $seconds,
            fn (): array => $this->memo($this->build($request)),
        );

        // The ranking is cached; the eligibility decision never is. A
        // product withdrawn since the ids were computed disappears from
        // the shelf on the next request, not in five minutes — which is
        // the whole reason this rebuilds from ids rather than from a
        // cached payload.
        return $this->present($request, $cached);
    }

    /**
     * Several slots at once, for a page that shows more than one shelf.
     *
     * Each set excludes what the earlier ones already showed, so a product
     * page does not put the same product in "bought together" and
     * "similar products" one above the other.
     *
     * @param  array<int, RecommendationSlot>  $slots
     * @return array<string, array<string, mixed>>
     */
    public function shelves(array $slots, RecommendationRequest $template): array
    {
        $shown = [];
        $shelves = [];

        foreach ($slots as $slot) {
            $request = new RecommendationRequest(
                slot: $slot,
                anchorProductId: $template->anchorProductId,
                userId: $template->userId,
                anonymousSessionId: $template->anonymousSessionId,
                limit: $slot->defaultLimit(),
                excludeProductIds: array_values(array_unique([...$template->excludeProductIds, ...$shown])),
                categoryId: $template->categoryId,
                basketProductIds: $template->basketProductIds,
            );

            $set = $this->for($request);

            if ($set->isEmpty()) {
                continue;
            }

            $shown = [...$shown, ...$set->productIds()];
            $shelves[$slot->value] = $set->toArray();
        }

        return $shelves;
    }

    private function build(RecommendationRequest $request): RecommendationSet
    {
        $chain = $this->strategiesFor($request->slot);
        $excluded = $request->excluded();

        /** @var array<int, int> $candidates */
        $candidates = [];
        /** @var array<int, string> $reasonById */
        $reasonById = [];
        /** @var array<int, string> $contributed */
        $contributed = [];
        /** @var array<int, RecommendedProduct> $products */
        $products = [];

        foreach ($chain as $strategy) {
            if (count($products) >= $request->limit) {
                break;
            }

            if (! $strategy->supports($request)) {
                continue;
            }

            $produced = $strategy->candidates($request);

            if ($produced === []) {
                continue;
            }

            $candidates = [...$candidates, ...$produced];
            $products = ($this->eligible)($candidates, $request->limit, $excluded);

            // A product is attributed to the first strategy that surfaced
            // it, not the last: when both "bought together" and "same
            // category" name a product, the stronger evidence is the true
            // reason it is on the shelf.
            foreach ($products as $product) {
                $reasonById[$product->productId] ??= $strategy->key();
            }

            if ($this->addedProducts($products, $reasonById, $strategy->key())) {
                $contributed[] = $strategy->key();
            }
        }

        $attributed = array_map(
            static fn (RecommendedProduct $product): RecommendedProduct => $product->withReason(
                $reasonById[$product->productId] ?? '',
            ),
            $products,
        );

        $firstInChain = $chain === [] ? null : $chain[0]->key();

        return new RecommendationSet(
            slot: $request->slot,
            products: $attributed,
            strategies: array_values(array_unique($contributed)),
            // §39 in one boolean: the shelf is filled, but not by the
            // strategy that was supposed to fill it. Surfaced so the admin
            // insight page can show which shelves are running cold rather
            // than everybody assuming the best strategy is working.
            usedFallback: $attributed !== [] && $contributed !== [] && $contributed[0] !== $firstInChain,
        );
    }

    /**
     * Whether this strategy is the reason any product is on the shelf.
     *
     * @param  array<int, RecommendedProduct>  $products
     * @param  array<int, string>  $reasonById
     */
    private function addedProducts(array $products, array $reasonById, string $key): bool
    {
        foreach ($products as $product) {
            if (($reasonById[$product->productId] ?? null) === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * What is worth remembering about a built shelf.
     *
     * Ids and attribution, never the rendered products: a cached price or
     * a cached "in stock" would be a second copy of something the
     * catalogue already owns, and the two would disagree.
     *
     * @return array{ids: array<int, int>, reasons: array<int, string>, strategies: array<int, string>, fallback: bool}
     */
    private function memo(RecommendationSet $set): array
    {
        $reasons = [];

        foreach ($set->products as $product) {
            $reasons[$product->productId] = $product->reason;
        }

        return [
            'ids' => $set->productIds(),
            'reasons' => $reasons,
            'strategies' => $set->strategies,
            'fallback' => $set->usedFallback,
        ];
    }

    /**
     * Turn a remembered ranking back into a shelf, through the gate.
     *
     * @param  array{ids: array<int, int>, reasons: array<int, string>, strategies: array<int, string>, fallback: bool}  $memo
     */
    private function present(RecommendationRequest $request, array $memo): RecommendationSet
    {
        $products = ($this->eligible)($memo['ids'], $request->limit, $request->excluded());

        $attributed = array_map(
            static fn (RecommendedProduct $product): RecommendedProduct => $product->withReason(
                $memo['reasons'][$product->productId] ?? '',
            ),
            $products,
        );

        return new RecommendationSet(
            slot: $request->slot,
            products: $attributed,
            strategies: $memo['strategies'],
            usedFallback: $attributed !== [] && $memo['fallback'],
        );
    }

    /** @return array<int, RecommendationStrategy> */
    private function strategiesFor(RecommendationSlot $slot): array
    {
        $classes = self::chains()[$slot->value] ?? [];
        $strategies = [];

        foreach ($classes as $class) {
            $strategy = $this->container->make($class);

            if ($strategy instanceof RecommendationStrategy) {
                $strategies[] = $strategy;
            }
        }

        return $strategies;
    }

    /** Null when the slot must not be shared between visitors. */
    private function cacheKey(RecommendationRequest $request): ?string
    {
        if ($request->slot->isPersonal()) {
            return null;
        }

        return implode(':', [
            'reco',
            $request->slot->value,
            $request->anchorProductId ?? 0,
            $request->categoryId ?? 0,
            $request->limit,
            md5(implode(',', $request->excluded())),
        ]);
    }

    private function cacheSeconds(): int
    {
        $seconds = config('veritas.recommendations.cache_seconds');

        return is_numeric($seconds) ? max(0, (int) $seconds) : 0;
    }
}
