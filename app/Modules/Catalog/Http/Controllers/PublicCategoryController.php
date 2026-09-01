<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Queries\BuildDiscoveryPage;
use App\Modules\Catalog\Queries\SearchQueryFactory;
use App\Modules\Catalog\Support\Indexability;
use App\Modules\Events\Actions\RecordInteraction;
use App\Modules\Events\Enums\InteractionEventType;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A category as a real discovery page.
 *
 * The same engine, cards and facets as search, with the category as a
 * fixed constraint rather than a filter the customer chose. Multiple
 * sellers of one product still produce one card: the catalogue is
 * canonical, and a listing page that showed a card per offer would be
 * three routes to the same product competing with each other.
 *
 * Only visible categories resolve. A hidden category is a 404 rather than
 * an empty page, so retiring part of the taxonomy actually retires it.
 */
final class PublicCategoryController
{
    public function __construct(
        private readonly SearchQueryFactory $queries,
        private readonly BuildDiscoveryPage $page,
        private readonly RecordInteraction $interactions,
    ) {}

    public function __invoke(Request $request, string $slug): Response
    {
        $category = Category::query()
            ->with('children')
            ->where('slug', $slug)
            ->where('is_visible', true)
            ->first();

        abort_if($category === null, 404);

        $query = ($this->queries)($request, $category);
        $page = ($this->page)($query);

        $base = rtrim((string) config('veritas.identity.public_url'), '/');
        $canonical = $base.'/categories/'.$category->slug;

        $this->interactions->record($request, InteractionEventType::CategoryViewed, payload: [
            'context' => 'category',
            'category' => $category->slug,
            'results' => $page['results']['total'],
        ]);

        return Inertia::render('Category/Show', [
            ...$page,
            'category' => [
                'name' => $category->name,
                'slug' => $category->slug,
                'description' => $category->description,
            ],
            'breadcrumbs' => $this->breadcrumbs($category),
            'children' => $category->children
                ->where('is_visible', true)
                ->map(static fn (Category $child): array => [
                    'name' => $child->name,
                    'slug' => $child->slug,
                ])
                ->values()
                ->all(),
            'seo' => [
                'title' => $category->seo_title ?? $category->name,
                'description' => $category->seo_description ?? $category->description,
                ...Indexability::forListing($canonical, $query),
            ],
        ]);
    }

    /**
     * The lineage, root first.
     *
     * Read from the stored path in one query rather than walked parent by
     * parent: §27 rules out loading the category tree recursively on every
     * request, and a breadcrumb is the most common place that happens.
     *
     * @return array<int, array<string, string>>
     */
    private function breadcrumbs(Category $category): array
    {
        $lineage = $category->ancestorIds() ?: [$category->id];

        return Category::query()
            ->whereIn('id', $lineage)
            ->orderBy('depth')
            ->get(['name', 'slug'])
            ->map(static fn (Category $ancestor): array => [
                'name' => $ancestor->name,
                'url' => '/categories/'.$ancestor->slug,
            ])
            ->all();
    }
}
