<?php

declare(strict_types=1);

namespace App\Modules\Search\Data;

/**
 * What the catalogue hands the search engine.
 *
 * A flat, engine-agnostic shape rather than an Eloquent model: the index
 * must not depend on how the catalogue stores things, and a document
 * carrying only what discovery needs is far easier to reason about than
 * one carrying everything.
 *
 * It is deliberately wide. Every field here is one a results page would
 * otherwise have to join back for — the price range, the availability, the
 * card's image — and a page of 24 results doing that 24 times is the
 * performance problem §42 exists to prevent.
 */
final readonly class IndexableProduct
{
    /**
     * @param  array<int, string>  $identifiers
     * @param  array<string, string>  $specifications  attribute name => displayed value
     * @param  array<int, int>  $categoryAncestorIds  the lineage, root first
     * @param  array<int, string>  $conditions  distinct conditions of eligible offers
     * @param  array<string, array<int, string>>  $filterableAttributes  code => values
     */
    public function __construct(
        public int $productId,
        public string $title,
        public string $slug,
        public ?string $description,
        public ?string $brandName,
        public ?int $brandId,
        public ?int $categoryId,
        public string $categoryPath,
        public array $categoryAncestorIds = [],
        public array $identifiers = [],
        public array $specifications = [],
        public array $filterableAttributes = [],
        public array $conditions = [],
        public bool $isPublic = false,
        public ?int $lowestPriceMinor = null,
        public ?int $highestPriceMinor = null,
        public ?string $currency = null,
        public int $offerCount = 0,
        public bool $inStock = false,
        public int $inStockOfferCount = 0,
        public ?string $imageDisk = null,
        public ?string $imagePath = null,
        public ?string $imageAlt = null,
        public ?string $publishedAt = null,
    ) {}

    /** Everything a keyword query should be able to reach, in one string. */
    public function searchableText(): string
    {
        return trim(implode(' ', array_filter([
            $this->title,
            $this->brandName,
            $this->categoryPath,
            $this->description,
            implode(' ', $this->identifiers),
            implode(' ', array_map(
                static fn (string $name, string $value): string => $name.' '.$value,
                array_keys($this->specifications),
                array_values($this->specifications),
            )),
        ])));
    }
}
