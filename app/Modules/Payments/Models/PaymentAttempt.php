<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Modules\Payments\Enums\PaymentStatus;
use App\Support\HasPublicId;
use Database\Factories\PaymentAttemptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * APPEND ONLY. Every attempt is a row, failures included — a customer
 * retrying three times produces three rows against one order, which is what
 * lets support answer "did it charge?" without opening the provider.
 */
final class PaymentAttempt extends Model
{
    /** @use HasFactory<PaymentAttemptFactory> */
    use HasFactory;

    use HasPublicId;

    protected $table = 'payment_attempts';

    public $timestamps = false;

    protected $fillable = [
        'marketplace_order_id', 'provider', 'provider_reference', 'method',
        'currency', 'amount_minor', 'status', 'failure_code', 'failure_message',
        'raw_response', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'amount_minor' => 'integer',
            'raw_response' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::deleting(function (): never {
            throw new RuntimeException('payment_attempts is append-only.');
        });
    }
}
