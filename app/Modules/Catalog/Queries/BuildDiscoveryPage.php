<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Queries;

use App\Modules\Catalog\Data\ProductCard;
use App\Modules\Catalog\Models\Attribute;
use App\Modules\Catalog\Models\AttributeOption;
use App\Modules\Catalog\Models\Category;
use App\Modules\Media\Contracts\ObjectStore;
use App\Modules\Search\Contracts\SearchIndex;
use App\Modules\Search\Data\FacetValue;
use App\Modules\Search\Data\SearchHit;
use App\Modules\Search\Data\SearchQuery;
use App\Modules\Search\Data\SearchResults;
use App\Modules\Search\Enums\SortOption;

/**
 * Everything a listing page needs, assembled once.
 *
 * Search, category pages and store pages are the same page with a
 * different starting constraint, so they share this: the same cards, the
 * same facets, the same sort options, the same pagination shape. Three
 * controllers each building their own would be three chances for the
 * filters to behave differently.
 */
final class BuildDiscoveryPage
{
    public function __construct(
        private readonly SearchIndex $index,
        private readonly ObjectStore $objects,
    ) {}

    /** @return array<string, mixed> */
    public function __invoke(SearchQuery $query): array
    {
        $results = $this->index->query($query);

        return [
            'results' => [
                'data' => array_map(
                    fn (SearchHit $hit): array => ProductCard::fromHit($hit, $this->objects)->toArray(),
                    $results->hits,
                ),
                'total' => $results->total,
                'page' => $results->page,
                'lastPage' => $results->lastPage(),
                'perPage' => $results->perPage,
            ],
            'facets' => $this->facets($results, $query),
            'sorts' => array_map(
                static fn (SortOption $option): array => [
                    'value' => $option->value,
                    'label' => $option->label(),
                ],
                SortOption::cases(),
            ),
            'applied' => $this->applied($query),
            'suggestion' => $results->suggestion,
        ];
    }

    /**
     * The facets the engine counted, plus the attribute facets this
     * category defines.
     *
     * §22: filters are generated from the category's own attribute
     * definitions, never hardcoded per category in React. A category with
     * no filterable attributes simply offers none.
     *
     * @return array<string, mixed>
     */
    private function facets(SearchResults $results, SearchQuery $query): array
    {
        $facets = [];

        foreach ($results->facets as $key => $values) {
            if ($values === []) {
                continue;
            }

            $facets[$key] = array_map(
                static fn (FacetValue $value): array => [
                    'value' => $value->value,
                    'label' => $value->label,
                    'count' => $value->count,
                    'selected' => $value->selected,
                ],
                $values,
            );
        }

        $facets['attributes'] = $this->attributeFacets($query);

        return $facets;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function attributeFacets(SearchQuery $query): array
    {
        if ($query->categoryId === null) {
            // Without a category there is no attribute vocabulary to offer:
            // "storage" means nothing on a page showing kettles and shoes.
            return [];
        }

        $category = Category::query()->find($query->categoryId);

        if ($category === null) {
            return [];
        }

        $attributes = $category->effectiveAttributes()
            ->filter(static fn (Attribute $attribute): bool => $attribute->is_filterable);

        $facets = [];

        foreach ($attributes as $attribute) {
            $options = $attribute->options
                ->map(static fn (AttributeOption $option): array => [
                    'value' => $option->value,
                    'label' => $option->label,
                ])
                ->all();

            if ($options === []) {
                // A free-text or numeric attribute has no fixed option
                // list, so it cannot become a checkbox facet. Offering an
                // empty one would be a control that does nothing.
                continue;
            }

            $facets[] = [
                'code' => $attribute->code,
                'name' => $attribute->name,
                'unit' => $attribute->unit,
                'options' => $options,
                'selected' => $query->attributes[$attribute->code] ?? [],
            ];
        }

        return $facets;
    }

    /**
     * What the customer currently has switched on, so the page can show
     * it back to them and offer to remove it.
     *
     * @return array<string, mixed>
     */
    private function applied(SearchQuery $query): array
    {
        return [
            'q' => $query->phrase,
            'brand' => array_map('strval', $query->brandIds),
            'condition' => $query->conditions,
            'attributes' => $query->attributes,
            'in_stock' => $query->inStockOnly,
            'min_price' => $query->minPriceMinor === null ? '' : (string) ($query->minPriceMinor / 100),
            'max_price' => $query->maxPriceMinor === null ? '' : (string) ($query->maxPriceMinor / 100),
            'sort' => $query->sort->value,
            'hasFilters' => $query->hasFilters(),
        ];
    }
}
