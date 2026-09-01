<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Queries;

use App\Modules\Catalog\Models\Attribute;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Offers\Enums\OfferCondition;
use App\Modules\Search\Data\SearchQuery;
use App\Modules\Search\Enums\SortOption;
use Illuminate\Http\Request;

/**
 * Turns a URL into a query the engine can trust.
 *
 * §23 in one class: filter parameters are untrusted input, and the answer
 * is neither to sanitise strings nor to escape them into SQL, but to
 * resolve every one of them against the catalogue before it goes anywhere
 * near a query. A brand id becomes a brand id only if that brand exists.
 * An attribute code survives only if a moderator marked it filterable. A
 * sort survives only if it is one of four.
 *
 * Anything unrecognised is dropped rather than rejected. A customer
 * following a stale link from last month should see results, not an error
 * page — and an attacker probing parameter names should see exactly the
 * same thing as that customer: their input, quietly ignored.
 *
 * Lives in the catalogue because only the catalogue knows what is
 * filterable. Search receives a validated value object and never has to
 * ask.
 */
final class SearchQueryFactory
{
    /**
     * One page size everywhere.
     *
     * §43 asks for a single consistent listing default; 24 divides evenly
     * into two, three and four columns, so no row is ever left ragged.
     */
    private const PER_PAGE = 24;

    public function __invoke(Request $request, ?Category $category = null, ?int $sellerAccountId = null): SearchQuery
    {
        $categoryId = $category === null ? $this->resolveCategory($request) : $category->id;

        return new SearchQuery(
            phrase: mb_substr(trim($request->string('q')->toString()), 0, 200),
            categoryId: $categoryId,
            brandIds: $this->resolveBrands($request),
            minPriceMinor: $this->resolvePrice($request, 'min_price'),
            maxPriceMinor: $this->resolvePrice($request, 'max_price'),
            conditions: $this->resolveConditions($request),
            attributes: $this->resolveAttributes($request, $categoryId),
            inStockOnly: $request->boolean('in_stock'),
            sort: SortOption::fromRequest($request->string('sort')->toString()),
            page: max(1, (int) $request->integer('page', 1)),
            perPage: self::PER_PAGE,
            sellerAccountId: $sellerAccountId,
        );
    }

    private function resolveCategory(Request $request): ?int
    {
        $slug = trim($request->string('category')->toString());

        if ($slug === '') {
            return null;
        }

        // Resolved by slug against visible categories: a hidden category
        // is not a filter a customer may apply by guessing its id.
        return Category::query()
            ->where('slug', $slug)
            ->where('is_visible', true)
            ->value('id');
    }

    /** @return array<int, int> */
    private function resolveBrands(Request $request): array
    {
        $requested = $this->stringList($request, 'brand');

        if ($requested === []) {
            return [];
        }

        // Existence is the validation. Ids that do not resolve simply are
        // not in the result, so nothing unknown reaches the query.
        return Brand::query()
            ->whereIn('id', array_map('intval', $requested))
            ->where('is_active', true)
            ->pluck('id')
            ->map(static fn (int $id): int => $id)
            ->all();
    }

    /**
     * A price boundary in minor units.
     *
     * The URL carries major units because that is what a person types; the
     * conversion happens here, once, at the boundary — never in React and
     * never as a float.
     */
    private function resolvePrice(Request $request, string $key): ?int
    {
        $raw = trim($request->string($key)->toString());

        if ($raw === '' || ! is_numeric($raw)) {
            return null;
        }

        $minor = (int) round(((float) $raw) * 100);

        return $minor < 0 ? null : min($minor, 100_000_000);
    }

    /** @return array<int, string> */
    private function resolveConditions(Request $request): array
    {
        $requested = $this->stringList($request, 'condition');

        return array_values(array_filter(
            $requested,
            static fn (string $value): bool => OfferCondition::tryFrom($value) !== null,
        ));
    }

    /**
     * Attribute filters, kept only where the catalogue permits them.
     *
     * Two gates: the attribute has to exist and be marked filterable, and
     * — when a category is in play — it has to be one of that category's
     * own attributes. Filtering phones by shoe size is not a query anyone
     * meant to make.
     *
     * @return array<string, array<int, string>>
     */
    private function resolveAttributes(Request $request, ?int $categoryId): array
    {
        $raw = $request->input('attributes', []);

        if (! is_array($raw) || $raw === []) {
            return [];
        }

        $codes = array_values(array_filter(array_keys($raw), 'is_string'));

        if ($codes === []) {
            return [];
        }

        $permitted = Attribute::query()
            ->whereIn('code', $codes)
            ->where('is_filterable', true)
            ->where('is_active', true)
            ->when($categoryId !== null, function ($query) use ($categoryId): void {
                $lineage = $this->lineageOf((int) $categoryId);

                $query->whereHas('categories', function ($categories) use ($lineage): void {
                    $categories->whereIn('categories.id', $lineage);
                });
            })
            ->pluck('code')
            ->all();

        $filters = [];

        foreach ($permitted as $code) {
            $values = $raw[$code] ?? null;
            $values = is_array($values) ? $values : [$values];

            $clean = array_values(array_filter(
                array_map(static fn (mixed $value): string => is_scalar($value) ? mb_substr((string) $value, 0, 120) : '', $values),
                static fn (string $value): bool => $value !== '',
            ));

            if ($clean !== []) {
                $filters[(string) $code] = $clean;
            }
        }

        return $filters;
    }

    /** @return array<int, int> */
    private function lineageOf(int $categoryId): array
    {
        $category = Category::query()->find($categoryId);

        if ($category === null) {
            return [$categoryId];
        }

        return array_values(array_map('intval', $category->ancestorIds() ?: [$categoryId]));
    }

    /**
     * A repeated query parameter, as a list of strings.
     *
     * Accepts both `brand[]=1&brand[]=2` and `brand=1,2`, because both
     * appear in the wild and neither should produce a 500.
     *
     * @return array<int, string>
     */
    private function stringList(Request $request, string $key): array
    {
        $value = $request->input($key);

        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(
                static fn (mixed $item): string => is_scalar($item) ? trim((string) $item) : '',
                $value,
            ),
            static fn (string $item): bool => $item !== '',
        ));
    }
}
