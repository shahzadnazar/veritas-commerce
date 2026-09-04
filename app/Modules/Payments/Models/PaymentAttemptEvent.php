<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Modules\Payments\Enums\PaymentAttemptStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * One state change on a payment attempt. APPEND ONLY.
 *
 * §71 exists because of what an UPDATE would hide. A customer whose card
 * was declined twice before a third worked has an attempt whose current
 * state is "succeeded" — and the two declines are the entire story when
 * that same customer disputes the charge, or when a fraud review asks how
 * many cards were tried. The current state is a column; what happened is
 * these rows.
 *
 * @property int $id
 * @property int $payment_attempt_id
 * @property PaymentAttemptStatus|null $from_status
 * @property PaymentAttemptStatus $to_status
 * @property string|null $provider_status
 * @property string $source
 * @property int|null $provider_webhook_event_id
 * @property string|null $note
 * @property Carbon $created_at
 */
final class PaymentAttemptEvent extends Model
{
    protected $table = 'payment_attempt_events';

    public $timestamps = false;

    protected $fillable = [
        'payment_attempt_id', 'from_status', 'to_status', 'provider_status',
        'source', 'provider_webhook_event_id', 'note', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'from_status' => PaymentAttemptStatus::class,
            'to_status' => PaymentAttemptStatus::class,
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PaymentAttempt, $this> */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttempt::class, 'payment_attempt_id');
    }

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new RuntimeException('payment_attempt_events is append-only.');
        });

        self::deleting(function (): never {
            throw new RuntimeException('payment_attempt_events is append-only.');
        });
    }
}
