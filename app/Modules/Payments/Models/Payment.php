<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Modules\Payments\Enums\PaymentStatus;
use App\Support\HasPublicId;
use App\Support\Money;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    use HasPublicId;

    protected $table = 'payments';

    protected $fillable = [
        'marketplace_order_id', 'payment_attempt_id', 'provider',
        'provider_charge_id', 'currency', 'amount_minor',
        'refunded_amount_minor', 'status', 'captured_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'amount_minor' => 'integer',
            'refunded_amount_minor' => 'integer',
            'captured_at' => 'datetime',
        ];
    }

    public function amount(): Money
    {
        return Money::of($this->amount_minor, $this->currency);
    }

    public function refundableRemaining(): int
    {
        return $this->amount_minor - $this->refunded_amount_minor;
    }
}
