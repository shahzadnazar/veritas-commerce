<?php

declare(strict_types=1);

namespace App\Modules\Customers\Queries;

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Customers\Data\WishlistEntry;
use App\Modules\Media\Contracts\ObjectStore;
use App\Modules\Media\Enums\Visibility;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * A customer's saved products, newest first.
 *
 * One query. The card fields come from the search document, the same
 * source a listing page uses, so a saved product and a searched one show
 * the same price and the same availability — and the wishlist does not
 * become a fourth place that formats a price its own way.
 *
 * Unlike the recommendation gate, this does **not** filter out ineligible
 * products. A customer's own list is theirs, and a product that has been
 * withdrawn is shown as unavailable rather than deleted from under them.
 * That is the deliberate difference between "what shall we suggest" and
 * "what did you save".
 */
final class GetWishlist
{
    public function __construct(private readonly ObjectStore $objects) {}

    /** @return array<int, WishlistEntry> */
    public function __invoke(int $userId, int $limit = 100): array
    {
        $rows = DB::table('wishlist_items as w')
            ->join('products as p', 'p.id', '=', 'w.product_id')
            ->leftJoin('product_search_documents as d', 'd.product_id', '=', 'w.product_id')
            ->leftJoin('product_rating_summaries as r', 'r.product_id', '=', 'w.product_id')
            ->where('w.user_id', $userId)
            ->orderByDesc('w.id')
            ->limit($limit)
            ->select([
                'w.product_id', 'w.created_at as saved_at',
                'p.slug', 'p.title', 'p.status', 'p.merged_into_product_id',
                'd.brand_name', 'd.lowest_price_minor', 'd.highest_price_minor', 'd.currency',
                'd.offer_count', 'd.in_stock', 'd.is_public',
                'd.primary_image_disk', 'd.primary_image_path', 'd.primary_image_alt',
                'r.rating_average', 'r.published_review_count',
            ])
            ->get();

        return $rows->map(fn (stdClass $row): WishlistEntry => $this->toEntry($row))->all();
    }

    /**
     * Whether this customer has saved this product.
     *
     * One product, because one product page asks. There is deliberately
     * no bulk "which of these are saved" companion: the wishlist control
     * lives on the product page and the wishlist itself, not on listing
     * cards, and a helper written for a surface that does not exist is a
     * surface somebody will assume exists.
     */
    public function has(?int $userId, int $productId): bool
    {
        return $userId !== null && DB::table('wishlist_items')
            ->where('user_id', $userId)
            ->where('product_id', $productId)
            ->exists();
    }

    private function toEntry(stdClass $row): WishlistEntry
    {
        $lowest = $row->lowest_price_minor === null ? null : (int) $row->lowest_price_minor;
        $highest = $row->highest_price_minor === null ? null : (int) $row->highest_price_minor;

        $available = (string) $row->status === ProductStatus::Published->value
            && $row->merged_into_product_id === null
            && (bool) ($row->is_public ?? false)
            && (int) ($row->offer_count ?? 0) > 0;

        return new WishlistEntry(
            productId: (int) $row->product_id,
            slug: (string) $row->slug,
            title: (string) $row->title,
            brandName: is_string($row->brand_name) ? $row->brand_name : null,
            imageUrl: $this->imageUrl($row),
            imageAlt: is_string($row->primary_image_alt) ? $row->primary_image_alt : null,
            displayPrice: WishlistEntry::formatPrice(
                $lowest,
                $highest,
                is_string($row->currency) ? $row->currency : null,
            ),
            hasPriceRange: $lowest !== null && $highest !== null && $highest > $lowest,
            offerCount: (int) ($row->offer_count ?? 0),
            inStock: (bool) ($row->in_stock ?? false),
            isAvailable: $available,
            ratingAverage: $row->rating_average === null ? null : (float) $row->rating_average,
            ratingCount: (int) ($row->published_review_count ?? 0),
            savedAt: (string) $row->saved_at,
        );
    }

    private function imageUrl(stdClass $row): ?string
    {
        if (! is_string($row->primary_image_path) || $row->primary_image_path === '') {
            return null;
        }

        $disk = is_string($row->primary_image_disk) ? $row->primary_image_disk : '';

        return $this->objects->url($this->objects->fromReference(
            $disk.':'.$row->primary_image_path,
            Visibility::Public,
        ));
    }
}
