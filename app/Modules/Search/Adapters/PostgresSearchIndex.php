<?php

declare(strict_types=1);

namespace App\Modules\Search\Adapters;

use App\Modules\Offers\Enums\OfferCondition;
use App\Modules\Search\Contracts\SearchIndex;
use App\Modules\Search\Data\FacetValue;
use App\Modules\Search\Data\IndexableProduct;
use App\Modules\Search\Data\SearchHit;
use App\Modules\Search\Data\SearchQuery;
use App\Modules\Search\Data\SearchResults;
use App\Modules\Search\Data\Suggestion;
use App\Modules\Search\Enums\SortOption;
use App\Modules\Search\Support\SearchText;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Marketplace search over PostgreSQL's own indexes.
 *
 * No new infrastructure, and enough for this catalogue: a weighted
 * tsvector for relevance, trigram similarity for typos, GIN indexes for
 * array and jsonb filters. When scale demands OpenSearch, that is a second
 * class implementing this interface — no controller and no catalogue
 * action changes.
 *
 * RANKING is deterministic and explainable, in this order:
 *
 *   1. An exact identifier match. Someone who typed a barcode knows what
 *      they want, and nothing else should come first.
 *   2. An exact title match.
 *   3. A title prefix.
 *   4. Weighted full-text rank — title beats brand beats category beats
 *      description, by the weights baked into the generated column.
 *   5. Trigram similarity, so a misspelling still finds something.
 *
 * Ties break on availability and then on product id, so the same query
 * always returns the same order. Nothing statistical, nothing learned, and
 * no sponsored placement: a customer's results are not for sale in M3.
 *
 * Every parameter is bound. The only SQL assembled from input is the
 * jsonb containment for attribute filters, and those keys are validated
 * against the catalogue's own filterable attributes before they arrive.
 */
final class PostgresSearchIndex implements SearchIndex
{
    /** Below this, a trigram match is noise rather than a typo. */
    private const FUZZY_THRESHOLD = 0.3;

    public function index(IndexableProduct $product): void
    {
        // Upsert, because indexing runs on a retryable queue: the same job
        // delivered twice must leave one document, not two.
        DB::table('product_search_documents')->upsert(
            [[
                'product_id' => $product->productId,
                'slug' => $product->slug,
                'title' => $product->title,
                'normalised_title' => SearchText::normalise($product->title),
                'brand_name' => $product->brandName,
                'brand_id' => $product->brandId,
                'category_id' => $product->categoryId,
                'category_path' => $product->categoryPath,
                'category_ancestor_ids' => '{'.implode(',', $product->categoryAncestorIds).'}',
                'searchable_text' => $product->searchableText(),
                'identifiers' => $this->textArray($product->identifiers),
                'conditions' => $this->textArray($product->conditions),
                'attributes' => json_encode($product->filterableAttributes, JSON_THROW_ON_ERROR),
                'is_public' => $product->isPublic,
                'lowest_price_minor' => $product->lowestPriceMinor,
                'highest_price_minor' => $product->highestPriceMinor,
                'currency' => $product->currency,
                'offer_count' => $product->offerCount,
                'in_stock' => $product->inStock,
                'in_stock_offer_count' => $product->inStockOfferCount,
                'primary_image_disk' => $product->imageDisk,
                'primary_image_path' => $product->imagePath,
                'primary_image_alt' => $product->imageAlt,
                'published_at' => $product->publishedAt,
                'indexed_at' => Carbon::now(),
            ]],
            ['product_id'],
            [
                'slug', 'title', 'normalised_title', 'brand_name', 'brand_id', 'category_id',
                'category_path', 'category_ancestor_ids', 'searchable_text', 'identifiers',
                'conditions', 'attributes', 'is_public', 'lowest_price_minor',
                'highest_price_minor', 'currency', 'offer_count', 'in_stock',
                'in_stock_offer_count', 'primary_image_disk', 'primary_image_path',
                'primary_image_alt', 'published_at', 'indexed_at',
            ],
        );
    }

    public function forget(int $productId): void
    {
        DB::table('product_search_documents')->where('product_id', $productId)->delete();
    }

    /** @return array<int, int> */
    public function search(string $phrase, int $limit = 24): array
    {
        $results = $this->query(new SearchQuery(phrase: $phrase, perPage: $limit));

        return array_map(static fn (SearchHit $hit): int => $hit->productId, $results->hits);
    }

    public function query(SearchQuery $query): SearchResults
    {
        $base = $this->constrained($query);

        $total = (int) (clone $base)->count();

        if ($total === 0) {
            return new SearchResults(
                hits: [],
                total: 0,
                page: $query->page,
                perPage: $query->perPage,
                facets: $this->facets($query),
                suggestion: $query->hasPhrase() ? $this->spellingFor($query->phrase) : null,
            );
        }

        $rows = $this->ordered(clone $base, $query)
            ->offset($query->offset())
            ->limit($query->perPage)
            ->get();

        return new SearchResults(
            hits: $rows->map($this->toHit(...))->all(),
            total: $total,
            page: $query->page,
            perPage: $query->perPage,
            facets: $this->facets($query),
        );
    }

    /** @return array<int, Suggestion> */
    public function suggest(string $prefix, int $limit = 8): array
    {
        $prefix = SearchText::normalise($prefix);

        if (mb_strlen($prefix) < 2) {
            return [];
        }

        $like = $prefix.'%';
        $contains = '%'.$prefix.'%';

        /** @var array<int, stdClass> $products */
        $products = DB::table('product_search_documents')
            ->select('title', 'slug', 'brand_name')
            // Only what is already public: autocomplete must not leak a
            // product still in moderation.
            ->where('is_public', true)
            ->where(fn (Builder $q) => $q->where('normalised_title', 'like', $like)
                ->orWhere('normalised_title', 'like', $contains))
            ->orderByRaw('case when normalised_title like ? then 0 else 1 end', [$like])
            ->orderBy('normalised_title')
            ->limit($limit)
            ->get()
            ->all();

        $suggestions = [];

        foreach ($products as $row) {
            $suggestions[] = new Suggestion(
                type: Suggestion::PRODUCT,
                label: (string) $row->title,
                url: '/products/'.$row->slug,
                context: $row->brand_name === null ? null : (string) $row->brand_name,
            );
        }

        foreach ($this->suggestBrands($like, $limit) as $suggestion) {
            $suggestions[] = $suggestion;
        }

        foreach ($this->suggestCategories($like, $limit) as $suggestion) {
            $suggestions[] = $suggestion;
        }

        return array_slice($suggestions, 0, $limit);
    }

    /**
     * Everything a query narrows to, before ordering.
     */
    private function constrained(SearchQuery $query): Builder
    {
        $builder = DB::table('product_search_documents')->where('is_public', true);

        if ($query->hasPhrase()) {
            $this->applyPhrase($builder, $query->phrase);
        }

        if ($query->categoryId !== null) {
            // Array containment against the GIN index: a category page
            // shows its descendants without a recursive walk per request.
            $builder->whereRaw('category_ancestor_ids @> ?::bigint[]', ['{'.$query->categoryId.'}']);
        }

        if ($query->brandIds !== []) {
            $builder->whereIn('brand_id', $query->brandIds);
        }

        if ($query->minPriceMinor !== null) {
            $builder->where('lowest_price_minor', '>=', $query->minPriceMinor);
        }

        if ($query->maxPriceMinor !== null) {
            $builder->where('lowest_price_minor', '<=', $query->maxPriceMinor);
        }

        if ($query->conditions !== []) {
            $builder->whereRaw('conditions && ?::text[]', [$this->textArray($query->conditions)]);
        }

        if ($query->inStockOnly) {
            $builder->where('in_stock', true);
        }

        foreach ($query->attributes as $code => $values) {
            if ($values === []) {
                continue;
            }

            /*
             * Containment, with the whole filter as one bound parameter.
             *
             * The code has already been checked against the category's
             * filterable attributes, and even so nothing from the request
             * is concatenated into SQL — the fragment is a constant and
             * the JSON is a binding.
             */
            $builder->where(function (Builder $inner) use ($code, $values): void {
                foreach ($values as $value) {
                    $inner->orWhereRaw('attributes @> ?::jsonb', [
                        json_encode([$code => [$value]], JSON_THROW_ON_ERROR),
                    ]);
                }
            });
        }

        if ($query->sellerAccountId !== null) {
            // A store page shows the store's own listings. The seller lives
            // on the offer, so this is the one place discovery joins back.
            $builder->whereExists(function (Builder $offers) use ($query): void {
                $offers->select(DB::raw(1))
                    ->from('offers')
                    ->whereColumn('offers.product_id', 'product_search_documents.product_id')
                    ->where('offers.seller_account_id', $query->sellerAccountId)
                    ->where('offers.status', 'published');
            });
        }

        return $builder;
    }

    /**
     * Full text first, trigram as the safety net.
     *
     * websearch_to_tsquery understands what people actually type — quoted
     * phrases, "or", a leading minus — and does not throw on punctuation
     * the way to_tsquery does. When it matches nothing, similarity catches
     * the misspelling.
     */
    private function applyPhrase(Builder $builder, string $phrase): void
    {
        $normalised = SearchText::normalise($phrase);

        $builder->where(function (Builder $inner) use ($phrase, $normalised): void {
            $inner->whereRaw("search_vector @@ websearch_to_tsquery('english', ?)", [$phrase])
                ->orWhereRaw('identifiers @> ?::text[]', [$this->textArray([$normalised])])
                /*
                 * word_similarity, not similarity.
                 *
                 * similarity() compares whole strings, so "iphnoe"
                 * against "iphone 15 pro" scores 0.17 — diluted by the
                 * words the customer did not type. word_similarity finds
                 * the best-matching run inside the title and scores 0.43,
                 * while still giving an unrelated casserole dish a 0.14.
                 * The threshold separates them; the whole-string version
                 * could not.
                 */
                ->orWhereRaw('word_similarity(?, normalised_title) > ?', [$normalised, self::FUZZY_THRESHOLD]);
        });
    }

    private function ordered(Builder $builder, SearchQuery $query): Builder
    {
        $sort = $query->sort->resolvedFor($query->hasPhrase());

        return match ($sort) {
            SortOption::PriceAscending => $this->thenAvailability(
                $builder->orderByRaw('lowest_price_minor asc nulls last'),
            ),
            SortOption::PriceDescending => $this->thenAvailability(
                $builder->orderByRaw('lowest_price_minor desc nulls last'),
            ),
            SortOption::Newest => $this->thenAvailability(
                $builder->orderByRaw('published_at desc nulls last'),
            ),
            SortOption::Relevance => $this->orderByRelevance($builder, $query->phrase),
        };
    }

    /**
     * The availability policy, applied to every sort.
     *
     * §1 of the M4 brief, and the only place it is expressed: an
     * out-of-stock product keeps its page, its indexability and its
     * presence in results, but where two results are otherwise equivalent
     * the one a customer can actually buy comes first. "Otherwise
     * equivalent" is what makes this a tie-break rather than a filter —
     * it never reorders a cheaper result below a dearer one.
     *
     * Applied here rather than per sort so a fifth sort added later cannot
     * quietly forget it, and nowhere in React, which could not see the
     * whole result set anyway.
     */
    private function thenAvailability(Builder $builder): Builder
    {
        return $builder
            ->orderByRaw('in_stock desc')
            // Last, always: two identical results must not swap places
            // between requests.
            ->orderBy('product_id');
    }

    /**
     * The ranking ladder, as one expression a person can read.
     *
     * Each rung is a separate ORDER BY term rather than a weighted sum, so
     * "why is this first" always has a one-line answer: it matched the
     * barcode, or the title exactly, or it simply ranked higher. A single
     * blended score would make that unanswerable.
     */
    private function orderByRelevance(Builder $builder, string $phrase): Builder
    {
        $normalised = SearchText::normalise($phrase);

        $ranked = $builder
            ->orderByRaw('case when identifiers @> ?::text[] then 0 else 1 end', [$this->textArray([$normalised])])
            ->orderByRaw('case when normalised_title = ? then 0 else 1 end', [$normalised])
            ->orderByRaw('case when normalised_title like ? then 0 else 1 end', [$normalised.'%'])
            ->orderByRaw("ts_rank(search_vector, websearch_to_tsquery('english', ?)) desc", [$phrase])
            ->orderByRaw('word_similarity(?, normalised_title) desc', [$normalised]);

        // Availability and the stable id come from the shared policy, so
        // relevance cannot drift from the other three sorts.
        return $this->thenAvailability($ranked);
    }

    /**
     * Facet counts over the *filtered* set, minus the facet's own filter.
     *
     * A brand facet computed with the brand filter still applied would
     * always show one brand with a count and every other at zero, which is
     * a dead end rather than a choice. So each facet is counted against
     * every constraint except its own — the standard behaviour, and the
     * only one that lets a customer widen a search.
     *
     * Three grouped queries, not one per value: §25 rules out N+1 faceting.
     *
     * @return array<string, array<int, FacetValue>>
     */
    private function facets(SearchQuery $query): array
    {
        return array_filter([
            'brand' => $this->brandFacet($query),
            'condition' => $this->conditionFacet($query),
            'availability' => $this->availabilityFacet($query),
            'attributes' => [],
        ], static fn (array $values): bool => $values !== []);
    }

    /** @return array<int, FacetValue> */
    private function brandFacet(SearchQuery $query): array
    {
        $withoutBrand = new SearchQuery(
            phrase: $query->phrase,
            categoryId: $query->categoryId,
            minPriceMinor: $query->minPriceMinor,
            maxPriceMinor: $query->maxPriceMinor,
            conditions: $query->conditions,
            attributes: $query->attributes,
            inStockOnly: $query->inStockOnly,
            sellerAccountId: $query->sellerAccountId,
        );

        /** @var array<int, stdClass> $rows */
        $rows = $this->constrained($withoutBrand)
            ->select('brand_id', 'brand_name', DB::raw('count(*) as total'))
            ->whereNotNull('brand_id')
            ->groupBy('brand_id', 'brand_name')
            ->orderByDesc('total')
            ->orderBy('brand_name')
            ->limit(20)
            ->get()
            ->all();

        return array_map(
            fn (stdClass $row): FacetValue => new FacetValue(
                value: (string) $row->brand_id,
                label: (string) $row->brand_name,
                count: (int) $row->total,
                selected: in_array((int) $row->brand_id, $query->brandIds, true),
            ),
            $rows,
        );
    }

    /** @return array<int, FacetValue> */
    private function conditionFacet(SearchQuery $query): array
    {
        $withoutCondition = new SearchQuery(
            phrase: $query->phrase,
            categoryId: $query->categoryId,
            brandIds: $query->brandIds,
            minPriceMinor: $query->minPriceMinor,
            maxPriceMinor: $query->maxPriceMinor,
            attributes: $query->attributes,
            inStockOnly: $query->inStockOnly,
            sellerAccountId: $query->sellerAccountId,
        );

        // unnest turns the condition array into rows so one grouped query
        // counts every value, rather than one query per condition.
        /** @var array<int, stdClass> $rows */
        $rows = $this->constrained($withoutCondition)
            ->select(DB::raw('unnest(conditions) as condition'), DB::raw('count(*) as total'))
            ->groupBy('condition')
            ->orderByDesc('total')
            ->orderBy('condition')
            ->get()
            ->all();

        return array_map(
            fn (stdClass $row): FacetValue => new FacetValue(
                value: (string) $row->condition,
                label: OfferCondition::tryFrom((string) $row->condition)?->label() ?? (string) $row->condition,
                count: (int) $row->total,
                selected: in_array((string) $row->condition, $query->conditions, true),
            ),
            $rows,
        );
    }

    /** @return array<int, FacetValue> */
    private function availabilityFacet(SearchQuery $query): array
    {
        $withoutAvailability = new SearchQuery(
            phrase: $query->phrase,
            categoryId: $query->categoryId,
            brandIds: $query->brandIds,
            minPriceMinor: $query->minPriceMinor,
            maxPriceMinor: $query->maxPriceMinor,
            conditions: $query->conditions,
            attributes: $query->attributes,
            sellerAccountId: $query->sellerAccountId,
        );

        $inStock = (int) $this->constrained($withoutAvailability)->where('in_stock', true)->count();

        if ($inStock === 0) {
            return [];
        }

        return [new FacetValue(
            value: 'in_stock',
            label: 'In stock only',
            count: $inStock,
            selected: $query->inStockOnly,
        )];
    }

    /**
     * A better spelling, when there is one and the query found nothing.
     *
     * Trigram similarity against titles that do exist. Only offered above
     * a comfortable threshold: suggesting a word that is merely the least
     * bad match is worse than admitting there were no results.
     */
    private function spellingFor(string $phrase): ?string
    {
        $normalised = SearchText::normalise($phrase);

        if (mb_strlen($normalised) < 3) {
            return null;
        }

        /** @var stdClass|null $row */
        $row = DB::table('product_search_documents')
            ->select('title')
            ->where('is_public', true)
            ->whereRaw('similarity(normalised_title, ?) > ?', [$normalised, self::FUZZY_THRESHOLD])
            ->orderByRaw('word_similarity(?, normalised_title) desc', [$normalised])
            ->first();

        return $row === null ? null : (string) $row->title;
    }

    /**
     * @return array<int, Suggestion>
     */
    private function suggestBrands(string $like, int $limit): array
    {
        /** @var array<int, stdClass> $rows */
        $rows = DB::table('product_search_documents')
            ->select('brand_id', 'brand_name', DB::raw('count(*) as total'))
            ->where('is_public', true)
            ->whereNotNull('brand_id')
            ->whereRaw('lower(brand_name) like ?', [$like])
            ->groupBy('brand_id', 'brand_name')
            ->orderByDesc('total')
            ->limit(max(1, (int) floor($limit / 4)))
            ->get()
            ->all();

        return array_map(
            static fn (stdClass $row): Suggestion => new Suggestion(
                type: Suggestion::BRAND,
                label: (string) $row->brand_name,
                url: '/search?brand[]='.$row->brand_id,
                context: $row->total.' products',
            ),
            $rows,
        );
    }

    /**
     * @return array<int, Suggestion>
     */
    private function suggestCategories(string $like, int $limit): array
    {
        /** @var array<int, stdClass> $rows */
        $rows = DB::table('categories')
            ->select('id', 'name', 'slug')
            ->where('is_visible', true)
            ->whereRaw('lower(name) like ?', [$like])
            ->orderBy('depth')
            ->orderBy('name')
            ->limit(max(1, (int) floor($limit / 4)))
            ->get()
            ->all();

        return array_map(
            static fn (stdClass $row): Suggestion => new Suggestion(
                type: Suggestion::CATEGORY,
                label: (string) $row->name,
                url: '/categories/'.$row->slug,
                context: 'Category',
            ),
            $rows,
        );
    }

    private function toHit(stdClass $row): SearchHit
    {
        return new SearchHit(
            productId: (int) $row->product_id,
            slug: (string) $row->slug,
            title: (string) $row->title,
            brandName: $row->brand_name === null ? null : (string) $row->brand_name,
            lowestPriceMinor: $row->lowest_price_minor === null ? null : (int) $row->lowest_price_minor,
            highestPriceMinor: $row->highest_price_minor === null ? null : (int) $row->highest_price_minor,
            currency: $row->currency === null ? null : (string) $row->currency,
            offerCount: (int) $row->offer_count,
            inStock: (bool) $row->in_stock,
            imageDisk: $row->primary_image_disk === null ? null : (string) $row->primary_image_disk,
            imagePath: $row->primary_image_path === null ? null : (string) $row->primary_image_path,
            imageAlt: $row->primary_image_alt === null ? null : (string) $row->primary_image_alt,
        );
    }

    /** @param  array<int, string>  $values */
    private function textArray(array $values): string
    {
        $escaped = array_map(
            static fn (string $value): string => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"',
            $values,
        );

        return '{'.implode(',', $escaped).'}';
    }
}
