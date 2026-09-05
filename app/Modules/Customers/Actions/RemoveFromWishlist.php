<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Customers\Models\WishlistItem;

/**
 * Take a product off a customer's list.
 *
 * Scoped by user id in the delete itself, not checked afterwards: there is
 * no code path here that loads somebody else's row and then decides not to
 * delete it, so a guessed product id removes nothing.
 *
 * Returns whether anything was removed, so a caller can tell "unsaved" from
 * "was not saved" — but both are success. Removing something twice is not
 * an error worth showing anybody.
 */
final class RemoveFromWishlist
{
    public function __invoke(int $userId, int $productId): bool
    {
        return WishlistItem::query()
            ->where('user_id', $userId)
            ->where('product_id', $productId)
            ->delete() > 0;
    }
}
