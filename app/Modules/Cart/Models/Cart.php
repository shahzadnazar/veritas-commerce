<?php

declare(strict_types=1);

namespace App\Modules\Cart\Models;

use App\Modules\Cart\Enums\CartStatus;
use App\Modules\Identity\Models\User;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer's intent, before it becomes a commitment.
 *
 * Belongs to a signed-in customer or to a browser session, never neither —
 * a database CHECK enforces it. At most one is active for either owner,
 * by partial unique index, so "the cart" is never an ambiguous question.
 *
 * Deliberately holds no financial record. Prices here are display values
 * re-read from the live offer on every view; the order is where money
 * stops moving.
 */
final class Cart extends Model
{
    use HasPublicId;

    protected $fillable = [
        'user_id', 'session_token', 'status', 'currency', 'expires_at', 'last_activity_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => CartStatus::class,
            'expires_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<CartItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function isEmpty(): bool
    {
        return $this->items()->doesntExist();
    }

    /** Every mutation touches this, so an abandonment sweep has a clock. */
    public function touchActivity(): void
    {
        $this->forceFill(['last_activity_at' => now()])->save();
    }
}
