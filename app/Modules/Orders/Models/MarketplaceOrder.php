<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Orders\Enums\MarketplaceOrderStatus;
use App\Support\HasPublicId;
use App\Support\Money;
use Database\Factories\MarketplaceOrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The customer-level purchase: one checkout, one number, one payment.
 *
 *   VC-24081
 *   ├── VC-24081-01  Seller A
 *   ├── VC-24081-02  Seller B
 *   └── VC-24081-03  Seller C
 */
final class MarketplaceOrder extends Model
{
    /** @use HasFactory<MarketplaceOrderFactory> */
    use HasFactory;

    use HasPublicId;

    protected $table = 'marketplace_orders';

    protected $fillable = [
        'reference', 'user_id', 'email', 'status', 'currency',
        'items_total_minor', 'shipping_total_minor', 'tax_total_minor',
        'discount_total_minor', 'grand_total_minor',
        'ship_name', 'ship_line1', 'ship_line2', 'ship_city', 'ship_state',
        'ship_postcode', 'ship_country', 'ship_phone', 'placed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => MarketplaceOrderStatus::class,
            'placed_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return HasMany<SellerOrder, $this> */
    public function sellerOrders(): HasMany
    {
        return $this->hasMany(SellerOrder::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function grandTotal(): Money
    {
        return Money::of($this->grand_total_minor, $this->currency);
    }
}
