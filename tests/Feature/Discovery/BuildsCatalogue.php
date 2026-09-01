<?php

declare(strict_types=1);

namespace Tests\Feature\Discovery;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Queries\BuildIndexableProduct;
use App\Modules\Search\Contracts\SearchIndex;

/**
 * Reindexing, done the way the queue job does it.
 *
 * Discovery tests need the index current, and calling the same source and
 * the same adapter the job calls is what keeps them testing the real path
 * rather than a convenient shortcut.
 */
trait BuildsCatalogue
{
    protected function reindex(Product $product): void
    {
        $document = app(BuildIndexableProduct::class)->describe($product->id);
        $index = app(SearchIndex::class);

        if ($document === null) {
            $index->forget($product->id);

            return;
        }

        $index->index($document);
    }
}
