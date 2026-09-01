<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Listeners;

use App\Modules\Catalog\Events\ProductApproved;
use App\Modules\Catalog\Events\ProductEdited;
use App\Modules\Catalog\Events\ProductPublished;
use App\Modules\Catalog\Events\ProductSuspended;
use App\Modules\Inventory\Events\InventoryAdjusted;
use App\Modules\Offers\Events\OfferActivated;
use App\Modules\Offers\Events\OfferSuspended;
use App\Modules\Offers\Events\OfferUpdated;
use App\Modules\Offers\Models\Offer;
use App\Modules\Search\Jobs\ReindexProduct;

/**
 * Anything that changes what a customer would see queues a reindex.
 *
 * Reindexing is deliberately a side effect rather than part of the
 * transaction: a search engine being slow must never fail an approval, and
 * an index a minute out of date is a far smaller problem than a moderator
 * unable to approve anything.
 */
final class KeepSearchIndexCurrent
{
    public function productChanged(ProductApproved|ProductEdited|ProductPublished|ProductSuspended $event): void
    {
        ReindexProduct::dispatch($event->productId);
    }

    /**
     * Stock moved, so what a customer would see has changed.
     *
     * The document carries availability precisely so a results page does
     * not query inventory per card — which only works if a stock change
     * rebuilds the document. The event already knows the product, so this
     * costs no lookup.
     */
    public function stockChanged(InventoryAdjusted $event): void
    {
        ReindexProduct::dispatch($event->productId);
    }

    public function offerChanged(OfferActivated|OfferSuspended|OfferUpdated $event): void
    {
        // The offer changed; the product's document is what carries its
        // price and availability, so that is what is rebuilt.
        $productId = Offer::query()->whereKey($event->offerId)->value('product_id');

        if ($productId !== null) {
            ReindexProduct::dispatch((int) $productId);
        }
    }
}
