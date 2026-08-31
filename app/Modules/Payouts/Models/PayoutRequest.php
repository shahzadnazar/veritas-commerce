<?php

declare(strict_types=1);

namespace App\Modules\Payouts\Models;

use App\Modules\Payouts\Enums\PayoutStatus;
use App\Modules\Sellers\Concerns\BelongsToSellerAccount;
use App\Modules\Sellers\Models\SellerAccount;
use App\Support\HasPublicId;
use App\Support\Money;
use Database\Factories\PayoutRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PayoutRequest extends Model
{
    /** @use HasFactory<PayoutRequestFactory> */
    use BelongsToSellerAccount;

    use HasFactory;
    use HasPublicId;

    protected $table = 'payout_requests';

    protected $fillable = [
        'reference', 'seller_account_id', 'seller_bank_account_id',
        'currency', 'amount_minor', 'status', 'requested_at',
        'decided_at', 'decided_by_admin_id', 'decision_reason', 'settlement_ref',
    ];

    protected function casts(): array
    {
        return [
            'status' => PayoutStatus::class,
            'amount_minor' => 'integer',
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SellerAccount, $this> */
    public function sellerAccount(): BelongsTo
    {
        return $this->belongsTo(SellerAccount::class);
    }

    public function amount(): Money
    {
        return Money::of($this->amount_minor, $this->currency);
    }
}
