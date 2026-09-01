<?php

declare(strict_types=1);

namespace App\Modules\Offers\Queries;

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Offers\Enums\OfferStatus;
use App\Modules\Offers\Models\Offer;
use App\Modules\Sellers\Enums\SellerStatus;
use Illuminate\Database\Eloquent\Builder;

/**
 * Whether an offer may be shown to a customer — decided once, here.
 *
 * Five things must all hold, and every public surface needs the same five:
 * the product page, the category page, a seller's store page and, in M3,
 * search. Writing the rule out four times is how a suspended seller ends
 * up visible on one page and not another, and how nobody can say which is
 * correct.
 *
 *   1. The seller is approved and trading.
 *   2. Their store is open to the public.
 *   3. The canonical product is published and not superseded by a merge.
 *   4. The offer itself is published.
 *   5. The offer names a variant that still belongs to that product.
 *
 * Stock is deliberately absent: an out-of-stock offer is a different
 * condition from an ineligible one, and M3 owns the difference.
 */
final class OfferEligibility
{
    /**
     * Constrain a query to offers a customer may see.
     *
     * @param  Builder<Offer>  $query
     */
    public function apply(Builder $query): void
    {
        $query
            ->where('offers.status', OfferStatus::Published->value)
            // Each closure receives the related model's own builder, so
            // the column names below are that model's, not the offer's.
            ->whereHas('product', function (Builder $product): void {
                $product->getQuery()
                    ->where('products.status', ProductStatus::Published->value)
                    ->whereNull('products.merged_into_product_id');
            })
            ->whereHas('sellerAccount', function (Builder $seller): void {
                $seller->getQuery()->where('seller_accounts.status', SellerStatus::Approved->value);
            })
            ->whereHas('store', function (Builder $store): void {
                $store->getQuery()->where('stores.is_open', true);
            });
    }

    /** @return Builder<Offer> */
    public function query(): Builder
    {
        $query = Offer::query();
        $this->apply($query);

        return $query;
    }

    /**
     * The same decision for one already-loaded offer.
     *
     * Kept beside the query so the two cannot drift: a rule enforced in SQL
     * and contradicted in PHP is worse than either alone.
     */
    public function permits(Offer $offer): bool
    {
        $product = $offer->product;
        $seller = $offer->sellerAccount;
        $store = $offer->store;

        return $offer->status === OfferStatus::Published
            && $product !== null
            && $product->isPubliclyVisible()
            && $seller !== null
            && $seller->status === SellerStatus::Approved
            && $store !== null
            && $store->is_open;
    }
}
