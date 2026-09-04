<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Modules\Payments\Enums\RefundStatus;
use App\Support\HasPublicId;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One refund request, and what the provider did with it.
 *
 * A refund is a request first and a movement second. The distinction is the
 * whole of §44: reversing a seller's earning the moment an admin presses
 * the button, and then having the provider refuse the refund, leaves the
 * seller short of money that never left the platform.
 *
 * Which money it reverses lives in the allocations underneath, because
 * "refund $50" is not a financial instruction until it says whose $50.
 *
 * @property int $id
 * @property string $public_id
 * @property string $reference
 * @property int $marketplace_order_id
 * @property int $payment_id
 * @property string $provider
 * @property string|null $provider_refund_reference
 * @property string|null $idempotency_key
 * @property string $currency
 * @property int $amount_minor
 * @property RefundStatus $status
 * @property string $reason
 * @property string|null $failure_code
 * @property string|null $failure_message
 * @property int|null $requested_by_admin_id
 * @property Carbon $requested_at
 * @property Carbon|null $succeeded_at
 * @property Carbon|null $failed_at
 */
final class Refund extends Model
{
    use HasPublicId;

    protected $table = 'refunds';

    protected $fillable = [
        'reference', 'marketplace_order_id', 'payment_id', 'provider',
        'provider_refund_reference', 'idempotency_key', 'currency', 'amount_minor',
        'status', 'reason', 'failure_code', 'failure_message',
        'requested_by_admin_id', 'requested_at', 'succeeded_at', 'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => RefundStatus::class,
            'amount_minor' => 'integer',
            'requested_at' => 'datetime',
            'succeeded_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /** @return HasMany<RefundAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(RefundAllocation::class);
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function amount(): Money
    {
        return Money::of($this->amount_minor, $this->currency);
    }
}
