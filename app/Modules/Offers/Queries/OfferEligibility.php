<?php

declare(strict_types=1);

namespace App\Modules\Offers\Queries;

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Offers\Enums\OfferStatus;
use App\Modules\Offers\Models\Offer;
use App\Modules\Sellers\Enums\SellerStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

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
 * Stock is deliberately NOT one of the five. An out-of-stock offer is a
 * different condition from an ineligible one — the product still has a
 * page, still ranks, and still says who sells it — so availability is
 * composed on top rather than folded in. `queryWithAvailability()` and
 * `buyable()` are how a caller asks for the stricter question, and the
 * separation is what lets the storefront show "out of stock" instead of
 * pretending the listing does not exist.
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

    /**
     * Eligible offers, each carrying how many units are actually sellable.
     *
     * One left join rather than a lookup per offer: a category page asks
     * this for every card on it, and §42 rules out a per-result inventory
     * query. `available` is the balance's generated column, so the number
     * is the database's own arithmetic.
     *
     * @return Builder<Offer>
     */
    public function queryWithAvailability(): Builder
    {
        return $this->query()
            ->leftJoin('inventory_balances', 'inventory_balances.offer_id', '=', 'offers.id')
            ->select('offers.*')
            ->addSelect(DB::raw('coalesce(inventory_balances.available, 0) as available_stock'));
    }

    /**
     * Eligible AND buyable right now.
     *
     * The narrower question, for the surfaces that should only offer what
     * a customer could actually put in a basket.
     *
     * @return Builder<Offer>
     */
    public function buyable(): Builder
    {
        return $this->queryWithAvailability()->whereRaw('coalesce(inventory_balances.available, 0) > 0');
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
