<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Queries\BuildProductPage;
use App\Modules\Catalog\Queries\FindPublicProduct;
use App\Modules\Catalog\Support\ProductStructuredData;
use Illuminate\Http\RedirectResponse;
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
    ) {}

    public function __invoke(string $slug): Response|RedirectResponse
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

        $page = ($this->buildPage)($product);
        $base = rtrim((string) config('veritas.identity.public_url'), '/');
        $canonical = $base.'/products/'.$product->slug;

        return Inertia::render('Product/Show', [
            ...$page,
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

    private function metaDescription(?string $seo, ?string $description, string $title): string
    {
        $text = $seo ?? $description;

        return $text === null
            ? $title.' on '.config('veritas.identity.display_name').'.'
            : mb_substr($text, 0, 155);
    }
}
