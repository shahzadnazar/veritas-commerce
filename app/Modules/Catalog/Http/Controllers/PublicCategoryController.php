<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Offers\Queries\OfferEligibility;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A category page: its children, and the products beneath it.
 *
 * Deliberately thin. Faceted filtering belongs to M3, and every facet
 * combination that becomes a crawlable URL is another near-duplicate page
 * competing with the canonical one — so the foundation is laid without
 * opening that door.
 */
final class PublicCategoryController
{
    public function __construct(private readonly OfferEligibility $eligibility) {}

    public function __invoke(string $slug): Response
    {
        $category = Category::query()
            ->with('children')
            ->where('slug', $slug)
            ->where('is_visible', true)
            ->first();

        abort_if($category === null, 404);

        $lineage = $category->ancestorIds() ?: [$category->id];
        $ancestors = Category::query()->whereIn('id', $lineage)->orderBy('depth')->get();

        // Everything at or beneath this category, found by path prefix
        // rather than a recursive walk.
        $descendantIds = Category::query()
            ->where('path', 'like', rtrim((string) $category->path, '/').'%')
            ->pluck('id');

        $products = Product::query()
            ->published()
            ->with(['brand', 'media'])
            ->whereIn('category_id', $descendantIds)
            // Only products somebody is actually selling. A category page
            // full of unbuyable entries is worse than a shorter one.
            ->whereIn('id', $this->eligibility->query()->select('product_id'))
            ->orderBy('title')
            ->paginate(24)
            ->withQueryString();

        $base = rtrim((string) config('veritas.identity.public_url'), '/');
        $canonical = $base.'/categories/'.$category->slug;

        return Inertia::render('Category/Show', [
            'category' => [
                'name' => $category->name,
                'slug' => $category->slug,
                'description' => $category->description,
            ],
            'breadcrumbs' => $ancestors
                ->map(fn (Category $ancestor): array => [
                    'name' => $ancestor->name,
                    'url' => '/categories/'.$ancestor->slug,
                ])
                ->all(),
            'children' => $category->children
                ->where('is_visible', true)
                ->map(fn (Category $child): array => [
                    'name' => $child->name,
                    'slug' => $child->slug,
                ])
                ->values()
                ->all(),
            'products' => [
                'data' => array_map(
                    static fn (Product $product): array => [
                        'title' => $product->title,
                        'slug' => $product->slug,
                        'brand' => $product->brand?->name,
                    ],
                    $products->items(),
                ),
                'currentPage' => $products->currentPage(),
                'lastPage' => $products->lastPage(),
                'total' => $products->total(),
            ],
            'seo' => [
                'title' => $category->seo_title ?? $category->name,
                'description' => $category->seo_description
                    ?? $category->description
                    ?? $category->name.' on '.config('veritas.identity.display_name').'.',
                'canonical' => $canonical,
                // Page two and beyond, and any query string, stay out of
                // the index: the canonical page is the one that should
                // rank, not a hundred permutations of it.
                'robots' => $products->currentPage() === 1 ? 'index, follow' : 'noindex, follow',
            ],
        ]);
    }
}
