<?php

declare(strict_types=1);

namespace App\Modules\Search\Data;

/**
 * What the catalogue hands the search engine.
 *
 * A flat, engine-agnostic shape rather than an Eloquent model: the index
 * must not depend on how the catalogue stores things, and a document that
 * carries only what is searchable is far easier to reason about than one
 * that carries everything.
 */
final readonly class IndexableProduct
{
    /**
     * @param  array<int, string>  $identifiers
     * @param  array<string, string>  $specifications  attribute name => displayed value
     */
    public function __construct(
        public int $productId,
        public string $title,
        public ?string $description,
        public ?string $brandName,
        public string $categoryPath,
        public array $identifiers = [],
        public array $specifications = [],
        public bool $isPublic = false,
        public ?int $lowestPriceMinor = null,
        public int $offerCount = 0,
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
