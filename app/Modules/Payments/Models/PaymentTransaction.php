<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Modules\Payments\Enums\PaymentTransactionType;
use App\Support\HasPublicId;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * What money actually did. APPEND ONLY.
 *
 * Separate from the attempt's state on purpose. An attempt is a
 * conversation with a provider that ends in one of several ways; a
 * transaction is a movement that happened and cannot un-happen. A capture
 * and three partial refunds are four rows, and the net position of an order
 * is their sum — never a `refunded_amount` column somebody edits, which is
 * a financial history with the middle deleted.
 *
 * Amounts are signed: capture positive, refund negative.
 *
 * @property int $id
 * @property string $public_id
 * @property int $marketplace_order_id
 * @property int|null $payment_id
 * @property int|null $payment_attempt_id
 * @property string $provider
 * @property string|null $provider_transaction_reference
 * @property PaymentTransactionType $type
 * @property string $currency
 * @property int $amount_minor
 * @property string $status
 * @property Carbon $occurred_at
 */
final class PaymentTransaction extends Model
{
    use HasPublicId;

    protected $table = 'payment_transactions';

    protected $fillable = [
        'marketplace_order_id', 'payment_id', 'payment_attempt_id', 'provider',
        'provider_transaction_reference', 'type', 'currency', 'amount_minor',
        'status', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => PaymentTransactionType::class,
            'amount_minor' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    public function amount(): Money
    {
        return Money::of(abs($this->amount_minor), $this->currency);
    }

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new RuntimeException(
                'payment_transactions is append-only: post a further transaction rather than editing one.'
            );
        });

        self::deleting(function (): never {
            throw new RuntimeException('payment_transactions is append-only.');
        });
    }
}
