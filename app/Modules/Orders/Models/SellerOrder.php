<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use App\Modules\Orders\Enums\SellerOrderStatus;
use App\Modules\Sellers\Concerns\BelongsToSellerAccount;
use App\Modules\Sellers\Models\SellerAccount;
use App\Support\HasPublicId;
use App\Support\Money;
use Database\Factories\SellerOrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One seller's slice of a marketplace order: VC-24081-01.
 *
 * It independently owns fulfilment state, shipment, commission, earning,
 * refund allocation and payout eligibility. Tenant-scoped, so a seller
 * cannot load another seller's sub-order even with a hand-edited id.
 */
final class SellerOrder extends Model
{
    /** @use HasFactory<SellerOrderFactory> */
    use BelongsToSellerAccount;

    use HasFactory;
    use HasPublicId;

    protected $table = 'seller_orders';

    protected $fillable = [
        'reference', 'marketplace_order_id', 'seller_account_id', 'store_id',
        'position', 'status', 'currency',
        'items_total_minor', 'shipping_total_minor', 'tax_total_minor',
        'discount_total_minor', 'order_total_minor',
        'commission_total_minor', 'seller_earning_total_minor',
    ];

    protected function casts(): array
    {
        return [
            'status' => SellerOrderStatus::class,
            'confirmed_at' => 'datetime',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<MarketplaceOrder, $this> */
    public function marketplaceOrder(): BelongsTo
    {
        return $this->belongsTo(MarketplaceOrder::class);
    }

    /** @return BelongsTo<SellerAccount, $this> */
    public function sellerAccount(): BelongsTo
    {
        return $this->belongsTo(SellerAccount::class);
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** @return HasMany<OrderStatusHistory, $this> */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class);
    }

    /** @return HasMany<Shipment, $this> */
    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    public function orderTotal(): Money
    {
        return Money::of($this->order_total_minor, $this->currency);
    }

    public function commissionTotal(): Money
    {
        return Money::of($this->commission_total_minor, $this->currency);
    }

    public function sellerEarningTotal(): Money
    {
        return Money::of($this->seller_earning_total_minor, $this->currency);
    }
}
