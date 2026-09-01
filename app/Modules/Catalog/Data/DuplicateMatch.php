<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Data;

use App\Modules\Catalog\Models\Product;

/**
 * A product the catalogue already holds that looks like the one being
 * proposed, and why.
 */
final readonly class DuplicateMatch
{
    public function __construct(
        public Product $product,
        /** 'gtin', 'upc', 'ean', 'isbn', 'brand_model' or 'title'. */
        public string $signal,
        /** Whether the signal is strong enough to refuse outright. */
        public bool $isConclusive,
        public string $explanation,
    ) {}
}
