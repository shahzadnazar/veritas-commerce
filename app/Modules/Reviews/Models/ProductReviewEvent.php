<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Every state a review passed through, and who moved it. Append-only.
 *
 * §9: moderation history is never silently lost. A customer told their
 * review was hidden is entitled to know when and why, and a moderator
 * reviewing a colleague's decision needs the same. The review row carries
 * the current state; this carries the account of it.
 *
 * @property int $id
 * @property int $product_review_id
 * @property string|null $from_status
 * @property string $to_status
 * @property string|null $actor_type
 * @property int|null $actor_id
 * @property string|null $actor_label
 * @property string|null $reason
 * @property Carbon $created_at
 */
final class ProductReviewEvent extends Model
{
    protected $table = 'product_review_events';

    public $timestamps = false;

    protected $fillable = [
        'product_review_id', 'from_status', 'to_status',
        'actor_type', 'actor_id', 'actor_label', 'reason', 'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new RuntimeException('product_review_events is append-only.');
        });

        self::deleting(function (): never {
            throw new RuntimeException('product_review_events is append-only.');
        });
    }

    /** @return BelongsTo<ProductReview, $this> */
    public function review(): BelongsTo
    {
        return $this->belongsTo(ProductReview::class, 'product_review_id');
    }
}
