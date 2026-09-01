<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Models;

use App\Modules\Checkout\Enums\CheckoutStatus;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One customer's attempt to turn a cart into an order.
 *
 * The idempotency record §15 asks for. A double-click, a refresh, a retry
 * after a gateway timeout — all present the same key, and the key is
 * UNIQUE in the database, so the guarantee belongs to PostgreSQL rather
 * than to a hopeful read-then-write in PHP.
 *
 * Its public id doubles as the reservation reference: every hold this
 * attempt takes carries it, so releasing or committing them all is one
 * query, and an orphaned hold can always be traced back to the attempt
 * that took it.
 *
 * @property int $id
 * @property string $public_id
 * @property string $idempotency_key
 * @property int|null $user_id
 * @property int|null $cart_id
 * @property int|null $marketplace_order_id
 * @property CheckoutStatus $status
 * @property string $currency
 * @property int $items_total_minor
 * @property int $shipping_total_minor
 * @property int $tax_total_minor
 * @property int $grand_total_minor
 * @property array<string, mixed>|null $shipping_address
 * @property string|null $failure_reason
 * @property Carbon|null $expires_at
 * @property Carbon|null $completed_at
 */
final class CheckoutAttempt extends Model
{
    use HasPublicId;

    protected $fillable = [
        'idempotency_key', 'user_id', 'cart_id', 'marketplace_order_id',
        'status', 'currency', 'items_total_minor', 'shipping_total_minor',
        'tax_total_minor', 'grand_total_minor', 'shipping_address',
        'failure_reason', 'expires_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => CheckoutStatus::class,
            'shipping_address' => 'array',
            'items_total_minor' => 'integer',
            'shipping_total_minor' => 'integer',
            'tax_total_minor' => 'integer',
            'grand_total_minor' => 'integer',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** The reference every inventory hold this attempt took carries. */
    public function reservationReference(): string
    {
        return 'checkout:'.$this->public_id;
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
