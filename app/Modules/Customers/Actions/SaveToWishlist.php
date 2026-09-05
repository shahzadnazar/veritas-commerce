<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Models\WishlistItem;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Save a canonical product to a customer's list.
 *
 * Idempotent, and the database is what makes it so: `wishlist_items_one_
 * per_customer` is a unique index, so two taps on the heart — or two
 * tabs, or a double-submitted form — are one row and one `created_at`.
 * The insert is attempted and the duplicate caught, rather than checked
 * first and then inserted, because between a check and an insert is
 * exactly where the second tap lands.
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

        return DB::transaction(function () use ($userId, $productId): WishlistItem {
            try {
                /** @var WishlistItem $item */
                $item = WishlistItem::query()->create([
                    'user_id' => $userId,
                    'product_id' => $productId,
                ]);

                return $item;
            } catch (QueryException $exception) {
                if (! $this->isDuplicate($exception)) {
                    throw $exception;
                }

                /** @var WishlistItem $existing */
                $existing = WishlistItem::query()
                    ->where('user_id', $userId)
                    ->where('product_id', $productId)
                    ->firstOrFail();

                return $existing;
            }
        });
    }

    private function isDuplicate(QueryException $exception): bool
    {
        return $exception->getCode() === '23505'
            || str_contains($exception->getMessage(), 'wishlist_items_one_per_customer');
    }
}
