<?php

declare(strict_types=1);

namespace App\Modules\Customers\Models;

use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One product a customer saved.
 *
 * There is no `wishlists` table above this, on purpose. A customer has one
 * list, and the moment a parent row exists somebody has to decide what
 * happens when it is missing, whether it can be renamed, and which list a
 * "save" goes to — three questions M8 does not need to answer. Named
 * lists, if they ever arrive, add a nullable column here rather than a
 * migration that splits every existing row.
 *
 * Immutable once written: a save has no fields to edit. Changing your mind
 * is a delete, which is why there is no `updated_at`.
 *
 * @property int $id
 * @property string $public_id
 * @property int $user_id
 * @property int $product_id
 * @property Carbon $created_at
 */
final class WishlistItem extends Model
{
    use HasPublicId;

    protected $table = 'wishlist_items';

    public $timestamps = false;

    protected $fillable = ['user_id', 'product_id', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        self::creating(function (self $item): void {
            if ($item->created_at === null) {
                $item->created_at = Carbon::now();
            }
        });

        self::updating(function (): bool {
            // Nothing on a saved product is editable. A row that could be
            // repointed at a different product would let a wishlist entry
            // change what it means after the fact.
            return false;
        });
    }
}
