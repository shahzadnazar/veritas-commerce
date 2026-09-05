<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Queries\BuildProductPage;
use App\Modules\Catalog\Queries\FindPublicProduct;
use App\Modules\Catalog\Support\ProductStructuredData;
use App\Modules\Customers\Queries\GetWishlist;
use App\Modules\Events\Actions\RecordInteraction;
use App\Modules\Events\Enums\InteractionEventType;
use App\Modules\Recommendations\Data\RecommendationRequest;
use App\Modules\Recommendations\Enums\RecommendationSlot;
use App\Modules\Recommendations\RecommendationService;
use App\Modules\Reviews\Queries\GetProductReviews;
use App\Modules\Reviews\Queries\GetRatingSummary;
use App\Modules\Reviews\Queries\ReviewEligibility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The canonical product page — the marketplace's primary SEO surface.
 *
 * One page per product, never one per offer: four sellers listing the same
 * kettle must not become four competing pages splitting the authority of
 * one. The offers appear on this page, and the seller store pages link
 * here rather than duplicating it.
 */
final class PublicProductController
{
    public function __construct(
        private readonly FindPublicProduct $findProduct,
        private readonly BuildProductPage $buildPage,
        private readonly RecordInteraction $interactions,
        private readonly GetRatingSummary $rating,
        private readonly GetProductReviews $reviews,
        private readonly ReviewEligibility $eligibility,
        private readonly GetWishlist $wishlist,
        private readonly RecommendationService $recommendations,
    ) {}

    public function __invoke(Request $request, string $slug): Response|RedirectResponse
    {
        $product = ($this->findProduct)($slug);

        if ($product === null) {
            $target = $this->findProduct->redirectTargetFor($slug);

            // A renamed or merged product keeps its authority: the old
            // address moves permanently rather than 404ing.
            if ($target !== null && $target !== $slug) {
                return redirect()->route('products.show', ['slug' => $target], 301);
            }

            abort(404);
        }

        /*
         * The rating and the reviews are merged in here rather than built
         * inside BuildProductPage, so the catalogue does not have to know
         * that reviews exist.
         *
         * Both go into `$page`, which is what the Inertia props AND the
         * structured data are built from. That is §16 in one variable:
         * the page cannot show 4.6 while its markup claims 4.8, and it
         * cannot quote a review in JSON-LD that a visitor would not find
         * on the page, because there is one payload rather than two
         * computations that have to agree.
         */
        $viewer = $request->user('web');
        $viewerId = $viewer === null ? null : (int) $viewer->getAuthIdentifier();

        $page = [
            ...($this->buildPage)($product),
            'rating' => ($this->rating)((int) $product->id)->toArray(),
            'reviews' => [
                ...($this->reviews)((int) $product->id, (int) $request->integer('reviews', 1), $viewerId),
                'mine' => $this->reviews->mine((int) $product->id, $viewerId),
                'canWrite' => $this->canWrite($viewerId, (int) $product->id),
            ],
        ];

        $base = rtrim((string) config('veritas.identity.public_url'), '/');

        // Queued, so a view is recorded without the customer waiting for
        // the write — and never on a redirect, which is not a view.
        $this->interactions->record(
            $request,
            InteractionEventType::ProductViewed,
            productId: $product->id,
            payload: ['context' => 'product_page'],
        );
        $canonical = $base.'/products/'.$product->slug;

        return Inertia::render('Product/Show', [
            ...$page,
            'wishlist' => [
                'isAuthenticated' => $viewerId !== null,
                'isSaved' => $this->wishlist->has($viewerId, (int) $product->id),
            ],
            /*
             * Two shelves, and the second excludes what the first showed:
             * a product in "bought together" must not reappear one row
             * lower under "similar products". RecommendationService::
             * shelves() owns that rule so no page has to remember it.
             */
            'shelves' => $this->recommendations->shelves(
                [RecommendationSlot::BoughtTogether, RecommendationSlot::SimilarProducts],
                new RecommendationRequest(
                    slot: RecommendationSlot::BoughtTogether,
                    anchorProductId: (int) $product->id,
                    userId: $viewerId,
                ),
            ),
            'seo' => [
                'title' => $product->seo_title ?? $product->title,
                'description' => $this->metaDescription($product->seo_description, $product->description, $product->title),
                'canonical' => $canonical,
                'robots' => $page['offerCount'] > 0 ? 'index, follow' : 'noindex, follow',
                'ogTitle' => $product->title,
                'ogType' => 'product',
                'ogUrl' => $canonical,
                'ogImage' => $page['media'][0]['url'] ?? null,
            ],
            // Emitted as-is by the page. Everything in it is read from the
            // database; nothing is claimed that cannot be supported.
            'structuredData' => [
                ProductStructuredData::product($page, $canonical),
                ProductStructuredData::breadcrumbs($page['breadcrumbs'], $base),
            ],
        ]);
    }

    /**
     * Whether this visitor may write a review, and why not if they cannot.
     *
     * The answer comes from the order record — ReviewEligibility joins
     * order items to payments to seller orders — never from anything the
     * browser sent. A signed-out visitor is told to sign in rather than
     * shown a form that will refuse them (§4, §5).
     *
     * @return array<string, mixed>
     */
    private function canWrite(?int $viewerId, int $productId): array
    {
        if ($viewerId === null) {
            return ['allowed' => false, 'reason' => 'sign_in', 'message' => 'Sign in to review a product you bought.'];
        }

        $evidence = ($this->eligibility)($viewerId, $productId);

        return [
            'allowed' => $evidence->mayReview,
            'reason' => $evidence->reason?->value,
            'message' => $evidence->message(),
        ];
    }

    private function metaDescription(?string $seo, ?string $description, string $title): string
    {
        $text = $seo ?? $description;

        return $text === null
            ? $title.' on '.config('veritas.identity.display_name').'.'
            : mb_substr($text, 0, 155);
    }
}
