<?php

declare(strict_types=1);

namespace App\Modules\Search\Contracts;

use App\Modules\Search\Data\IndexableProduct;

/**
 * Where the index gets its documents.
 *
 * The search module knows how to index a description of a product; it does
 * not know how the catalogue stores one, and must not — reading Catalog's
 * models from here would make the index a second, quietly diverging
 * definition of what a product is.
 *
 * The catalogue implements this, because describing its own products is
 * the catalogue's job.
 */
interface IndexableProductSource
{
    /** Null when the product is gone or merged away, and should be dropped. */
    public function describe(int $productId): ?IndexableProduct;
}
