<?php

declare(strict_types=1);

namespace App\Modules\Search\Data;

use App\Modules\Search\Enums\SortOption;

/**
 * A validated discovery request.
 *
 * Everything reaching the engine has already been checked against the real
 * catalogue: category and brand ids exist, attribute codes are filterable,
 * prices are integers, and the sort is one of four. §23 is the point —
 * filter parameters are untrusted input, and no adapter should have to
 * remember that.
 *
 * Construct one with SearchQueryFactory rather than by hand, so validation
 * happens in exactly one place.
 */
final readonly class SearchQuery
{
    /**
     * @param  array<int, int>  $brandIds
     * @param  array<int, string>  $conditions
     * @param  array<string, array<int, string>>  $attributes  code => values
     */
    public function __construct(
        public string $phrase = '',
        public ?int $categoryId = null,
        public array $brandIds = [],
        public ?int $minPriceMinor = null,
        public ?int $maxPriceMinor = null,
        public array $conditions = [],
        public array $attributes = [],
        public bool $inStockOnly = false,
        public SortOption $sort = SortOption::Relevance,
        public int $page = 1,
        public int $perPage = 24,
        /** Restricts results to one seller, for a store page. */
        public ?int $sellerAccountId = null,
        /**
         * Whether the category came from the route rather than the customer.
         *
         * On /categories/kettles the category is the page's identity, not
         * a filter someone applied — which matters for indexability: a
         * clean category page is the canonical thing and should rank,
         * while /search?category=kettles is a permutation and should not.
         */
        public bool $scopeIsIntrinsic = false,
    ) {}

    public function hasPhrase(): bool
    {
        return trim($this->phrase) !== '';
    }

    /** Whether the customer narrowed this beyond the page they are on. */
    public function hasFilters(): bool
    {
        return ($this->categoryId !== null && ! $this->scopeIsIntrinsic)
            || $this->brandIds !== []
            || $this->minPriceMinor !== null
            || $this->maxPriceMinor !== null
            || $this->conditions !== []
            || $this->attributes !== []
            || $this->inStockOnly;
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /** The same query with its filters dropped, for a "no results" retry. */
    public function withoutFilters(): self
    {
        return new self(
            phrase: $this->phrase,
            sort: $this->sort,
            page: 1,
            perPage: $this->perPage,
            sellerAccountId: $this->sellerAccountId,
            scopeIsIntrinsic: $this->scopeIsIntrinsic,
        );
    }
}
