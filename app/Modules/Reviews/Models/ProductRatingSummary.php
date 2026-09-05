<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Models;

use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The rating a product page shows, precomputed.
 *
 * ONE ROW PER CANONICAL PRODUCT. Not per seller, not per offer — §3 is the
 * whole reason this table is keyed the way it is. Two shops selling the
 * same kettle contribute to one rating, and a shop that stops selling it
 * takes none of that rating away.
 *
 * Derived and rebuildable. Nothing reads this to make a decision that
 * matters; it exists so a product page does not aggregate every review on
 * every request. `reviews:reconcile-ratings` recomputes every row from
 * `product_reviews` and reports any that had drifted, which is what makes
 * a stale summary a bug that gets found rather than a number nobody can
 * check.
 *
 * @property int $id
 * @property int $product_id
 * @property int $published_review_count
 * @property int $verified_review_count
 * @property int $rating_sum
 * @property string|null $rating_average
 * @property int $count_1
 * @property int $count_2
 * @property int $count_3
 * @property int $count_4
 * @property int $count_5
 * @property Carbon|null $recomputed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Product|null $product
 */
final class ProductRatingSummary extends Model
{
    protected $table = 'product_rating_summaries';

    protected $fillable = [
        'product_id', 'published_review_count', 'verified_review_count',
        'rating_sum', 'rating_average',
        'count_1', 'count_2', 'count_3', 'count_4', 'count_5',
        'recomputed_at',
    ];

    protected function casts(): array
    {
        return [
            'published_review_count' => 'integer',
            'verified_review_count' => 'integer',
            'rating_sum' => 'integer',
            'count_1' => 'integer',
            'count_2' => 'integer',
            'count_3' => 'integer',
            'count_4' => 'integer',
            'count_5' => 'integer',
            'recomputed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Whether this product has anything to say about itself.
     *
     * The one question the product page and the structured data must
     * answer the same way (§67 and §69): no reviews means no rating shown
     * and no `aggregateRating` emitted, rather than a confident 0.0.
     */
    public function hasPublicRating(): bool
    {
        return $this->published_review_count > 0 && $this->rating_average !== null;
    }

    /** The average as a number, for arithmetic and for JSON-LD. */
    public function average(): ?float
    {
        return $this->rating_average === null ? null : (float) $this->rating_average;
    }
}
