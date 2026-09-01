<?php

declare(strict_types=1);

namespace App\Modules\Offers\Queries;

use App\Modules\Inventory\Data\StockLevel;
use App\Modules\Inventory\Enums\StockState;
use App\Modules\Offers\Models\Offer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The rows behind an inventory list, for a seller or for the platform.
 *
 * Lives in Offers because that is the module allowed to see both sides:
 * inventory belongs to an offer, and the product whose name labels the row
 * belongs to the catalogue. Composing them here keeps the Inventory module
 * from reaching across the catalogue boundary to render a title.
 *
 * One query with joins rather than a graph of models: an inventory page is
 * a table, and loading four relations per row to print six numbers is how
 * a listing becomes the slowest page in the portal.
 */
final class InventoryRows
{
    /** @return LengthAwarePaginator<int, Offer> */
    public function __invoke(Request $request, ?int $sellerAccountId = null): LengthAwarePaginator
    {
        $query = Offer::query()
            ->with(['product:id,title,slug', 'productVariant:id,name', 'store:id,name,slug,default_low_stock_threshold', 'sellerAccount:id,legal_name'])
            ->join('inventory_balances', 'inventory_balances.offer_id', '=', 'offers.id')
            // `available` is a generated column, so it is selected as an
            // expression: the schema readers static analysis uses do not
            // see generated columns declared in raw DDL.
            ->select('offers.*', 'inventory_balances.on_hand', 'inventory_balances.reserved')
            ->addSelect(DB::raw('inventory_balances.available as available'));

        if ($sellerAccountId !== null) {
            // Scoped in the query, never by a filter the request supplies.
            $query->where('offers.seller_account_id', $sellerAccountId);
        }

        $this->applySearch($query, trim($request->string('search')->toString()));
        $this->applyState($query, $request->string('state')->toString());

        return $query
            ->orderBy('inventory_balances.available')
            ->orderBy('offers.id')
            ->paginate(25)
            ->withQueryString();
    }

    /**
     * SKU or product title.
     *
     * Joined rather than an EXISTS subquery: the listing already joins the
     * balance, one more join is cheaper than a correlated lookup per row,
     * and the search term is bound rather than interpolated.
     *
     * @param  Builder<Offer>  $query
     */
    private function applySearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $query->join('products', 'products.id', '=', 'offers.product_id')
            ->whereRaw(
                '(offers.seller_sku ilike ? or products.title ilike ?)',
                ['%'.$search.'%', '%'.$search.'%'],
            );
    }

    /**
     * Filtering by stock state, expressed against the generated column.
     *
     * The thresholds are per offer, so "low" cannot be a fixed number in
     * SQL: it is available at or below whichever threshold applies, with
     * the offer's own overriding the store's overriding the platform's.
     * COALESCE puts that precedence in one place rather than three.
     *
     * The fallback is a bound parameter, not interpolated. Nothing from
     * the request reaches the SQL text — the state itself is matched
     * against the enum, so an unknown value filters nothing rather than
     * composing a fragment.
     *
     * @param  Builder<Offer>  $query
     */
    private function applyState(Builder $query, string $state): void
    {
        $state = StockState::tryFrom($state);

        if ($state === null) {
            return;
        }

        if ($state === StockState::OutOfStock) {
            $query->whereRaw('inventory_balances.available <= 0');

            return;
        }

        $fallback = (int) config('veritas.inventory.low_stock_threshold');
        $threshold = 'coalesce(offers.low_stock_threshold, stores.default_low_stock_threshold, ?)';

        $query->join('stores', 'stores.id', '=', 'offers.store_id')
            ->whereRaw('inventory_balances.available > 0');

        if ($state === StockState::LowStock) {
            $query->whereRaw("inventory_balances.available <= {$threshold} and {$threshold} > 0", [$fallback, $fallback]);

            return;
        }

        $query->whereRaw("(inventory_balances.available > {$threshold} or {$threshold} <= 0)", [$fallback, $fallback]);
    }

    /**
     * One row, shaped for the portal.
     *
     * @return array<string, mixed>
     */
    public static function present(Offer $offer): array
    {
        $threshold = $offer->low_stock_threshold
            ?? $offer->store->default_low_stock_threshold
            ?? (int) config('veritas.inventory.low_stock_threshold');

        $level = StockLevel::of(
            (int) $offer->getAttribute('on_hand'),
            (int) $offer->getAttribute('reserved'),
            $threshold,
        );

        return [
            'offerPublicId' => $offer->public_id,
            'sku' => $offer->seller_sku,
            'productTitle' => $offer->product->title ?? 'Unknown product',
            'productSlug' => $offer->product->slug ?? null,
            'variantName' => $offer->productVariant->name ?? null,
            'sellerName' => $offer->sellerAccount->legal_name ?? null,
            'storeName' => $offer->store->name ?? null,
            'offerStatus' => $offer->status->value,
            ...$level->toArray(),
        ];
    }
}
