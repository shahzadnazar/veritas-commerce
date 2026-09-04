<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Which item's money one refund reverses, and how it splits.
 *
 * The commission and earning figures here are COPIED from the order item's
 * snapshot at allocation time. §39 is the reason: an order taken at 10%
 * commission that is refunded after the platform moved to 15% reverses ten
 * percent, because that is what was charged. Recomputing from the current
 * rule would hand the seller back less than was taken from them, which is
 * theft with a rounding error's deniability.
 *
 * A database CHECK holds the split exact, the same way the order item's
 * does.
 *
 * @property int $id
 * @property int $refund_id
 * @property int $seller_order_id
 * @property int $order_item_id
 * @property string $currency
 * @property int $quantity
 * @property int $amount_minor
 * @property int $commission_reversed_minor
 * @property int $earning_reversed_minor
 * @property Carbon $created_at
 */
final class RefundAllocation extends Model
{
    protected $table = 'refund_allocations';

    public $timestamps = false;

    protected $fillable = [
        'refund_id', 'seller_order_id', 'order_item_id', 'currency',
        'quantity', 'amount_minor', 'commission_reversed_minor',
        'earning_reversed_minor', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'amount_minor' => 'integer',
            'commission_reversed_minor' => 'integer',
            'earning_reversed_minor' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Refund, $this> */
    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    public function amount(): Money
    {
        return Money::of($this->amount_minor, $this->currency);
    }

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new RuntimeException(
                'refund_allocations records what was reversed and cannot be edited.'
            );
        });
    }
}
