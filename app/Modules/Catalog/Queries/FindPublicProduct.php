<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Queries;

use App\Modules\Catalog\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Resolves a product for the storefront, or nothing.
 *
 * Eligibility is decided once, here, so every public surface agrees:
 *
 *   - published and unmerged → the page resolves;
 *   - merged into another    → 301 to the survivor, because the address
 *     accumulated authority that belongs to the product it became;
 *   - a retired slug         → 301 to the current address, for the same
 *     reason;
 *   - anything else          → 404, not an empty shell. A page for a
 *     product nobody can buy is still indexed and still linkable.
 */
final class FindPublicProduct
{
    public function __invoke(string $slug): ?Product
    {
        $product = Product::query()
            ->with([
                'brand',
                'category',
                'media',
                'variants',
                'attributeValues.attribute.options',
                'attributeValues.option',
            ])
            ->where('slug', $slug)
            ->first();

        return $product !== null && $product->isPubliclyVisible() ? $product : null;
    }

    /**
     * Where a slug that no longer resolves should send a visitor.
     *
     * Covers both reasons an address goes stale — the product was renamed,
     * or it was merged into another — because to a visitor and to a
     * crawler they are the same event.
     */
    public function redirectTargetFor(string $slug): ?string
    {
        $productId = DB::table('product_slug_history')
            ->where('old_slug', $slug)
            ->orderByDesc('changed_at')
            ->value('product_id');

        if ($productId === null) {
            $productId = Product::query()->where('slug', $slug)->value('id');
        }

        if ($productId === null) {
            return null;
        }

        $product = Product::query()->whereKey($productId)->first();

        // Follow a chain of merges to whatever is live at the end of it.
        $seen = [];

        while ($product !== null && $product->merged_into_product_id !== null) {
            if (in_array($product->id, $seen, true)) {
                // A cycle should be impossible — the schema forbids the
                // one-step case — but a redirect loop served to a crawler
                // is worse than a 404.
                return null;
            }

            $seen[] = $product->id;
            $product = $product->mergedInto;
        }

        return $product !== null && $product->isPubliclyVisible() ? $product->slug : null;
    }
}
