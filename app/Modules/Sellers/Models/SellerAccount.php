<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Models;

use App\Modules\Sellers\Enums\SellerStatus;
use App\Modules\Stores\Models\Store;
use App\Support\HasPublicId;
use Database\Factories\SellerAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class SellerAccount extends Model
{
    /** @use HasFactory<SellerAccountFactory> */
    use HasFactory;

    use HasPublicId;

    protected $table = 'seller_accounts';

    protected $fillable = [
        'legal_name',
        'business_type',
        'tax_id',
        'status',
        'ships_from_city',
        'ships_from_state',
        'clearing_period_days',
    ];

    protected function casts(): array
    {
        return [
            'status' => SellerStatus::class,
            'tax_id' => 'encrypted',
            'approved_at' => 'datetime',
            'suspended_at' => 'datetime',
        ];
    }

    /** @return HasOne<Store, $this> */
    public function store(): HasOne
    {
        return $this->hasOne(Store::class);
    }

    /** @return HasMany<SellerMembership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(SellerMembership::class);
    }

    /**
     * How long this seller's earnings clear before becoming withdrawable.
     *
     * Resolution order: the seller's own override, then the platform
     * setting. Never a literal in code — a future risk-based rule changes
     * this method and nothing else.
     */
    public function clearingPeriodDays(): int
    {
        return $this->clearing_period_days
            ?? (int) config('veritas.payouts.seller_clearing_period_days');
    }

    public function canSell(): bool
    {
        return $this->status->canSell();
    }
}
