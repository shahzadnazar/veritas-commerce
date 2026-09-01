<?php

declare(strict_types=1);

namespace App\Modules\Search\Data;

/**
 * One result, already shaped for a product card.
 *
 * A flat record rather than a model: the point of denormalising the search
 * document was that a page of results costs one query, and handing back
 * Eloquent models would put the joins straight back.
 */
final readonly class SearchHit
{
    public function __construct(
        public int $productId,
        public string $slug,
        public string $title,
        public ?string $brandName,
        public ?int $lowestPriceMinor,
        public ?int $highestPriceMinor,
        public ?string $currency,
        public int $offerCount,
        public bool $inStock,
        public ?string $imageDisk,
        public ?string $imagePath,
        public ?string $imageAlt,
        public float $score = 0.0,
    ) {}

    public function hasPriceRange(): bool
    {
        return $this->lowestPriceMinor !== null
            && $this->highestPriceMinor !== null
            && $this->highestPriceMinor > $this->lowestPriceMinor;
    }
}
