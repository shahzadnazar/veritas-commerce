<?php

declare(strict_types=1);

namespace App\Modules\Stores\Queries;

use App\Modules\Sellers\Concerns\CurrentSeller;
use App\Modules\Sellers\Enums\SellerStatus;
use App\Modules\Stores\Models\Store;
use Illuminate\Support\Facades\DB;

/**
 * Resolves a store for the public storefront, or nothing.
 *
 * Eligibility is a single decision made here, so every public surface
 * agrees: the seller must be approved and the store must be open. A
 * suspended seller's store must not resolve at all — an empty shell would
 * still be indexed, still be linkable, and still look like a shop that has
 * simply run out of stock.
 */
final class FindPublicStore
{
    public function __invoke(string $slug): ?Store
    {
        // Deliberately outside the tenant scope: this is the public
        // storefront, where no seller is acting.
        return CurrentSeller::withoutScope(function () use ($slug): ?Store {
            $store = Store::query()
                ->with('sellerAccount')
                ->where('slug', $slug)
                ->first();

            if ($store === null) {
                return null;
            }

            $seller = $store->sellerAccount;

            if ($seller === null || $seller->status !== SellerStatus::Approved) {
                return null;
            }

            return $store;
        });
    }

    /** The previous slug of a store that has been renamed, for a 301. */
    public function currentSlugForOldSlug(string $oldSlug): ?string
    {
        return CurrentSeller::withoutScope(function () use ($oldSlug): ?string {
            $storeId = DB::table('store_slug_history')
                ->where('old_slug', $oldSlug)
                ->orderByDesc('changed_at')
                ->value('store_id');

            if ($storeId === null) {
                return null;
            }

            return Store::query()->whereKey($storeId)->value('slug');
        });
    }
}
