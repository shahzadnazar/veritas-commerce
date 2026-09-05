<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Models\WishlistItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Save a canonical product to a customer's list.
 *
 * Idempotent, and the database is what makes it so. Two taps on the heart
 * — or two tabs, or a double-submitted form — are one row with one
 * `created_at`, because the insert carries `on conflict do nothing` and
 * the unique index `wishlist_items_one_per_customer` decides. Checking
 * first and inserting second would leave a gap between the two, and the
 * second tap lands in exactly that gap.
 *
 * `insertOrIgnore` rather than catching a duplicate-key exception: in
 * PostgreSQL a constraint violation aborts the surrounding transaction, so
 * the "recover by selecting the existing row" path would fail with a dead
 * transaction the moment this ran inside one. The conflict is declared
 * up front instead, and nothing is ever raised.
 *
 * §30 applies here too: a wishlist holds *products*. Saving a seller's
 * offer would mean the entry dies when that seller delists, and a customer
 * who saved a kettle wants the kettle, not the shop.
 */
final class SaveToWishlist
{
    public function __invoke(int $userId, int $productId): WishlistItem
    {
        $product = Product::query()->whereKey($productId)->first();

        if ($product === null || ! $product->isPubliclyVisible()) {
            // The same rule the product page uses. A customer cannot save
            // something they could not have been shown.
            throw new RuntimeException('That product is not available.');
        }

        DB::table('wishlist_items')->insertOrIgnore([
            'public_id' => (string) Str::ulid(),
            'user_id' => $userId,
            'product_id' => $productId,
            'created_at' => Carbon::now(),
        ]);

        /** @var WishlistItem $item */
        $item = WishlistItem::query()
            ->where('user_id', $userId)
            ->where('product_id', $productId)
            ->firstOrFail();

        return $item;
    }
}
