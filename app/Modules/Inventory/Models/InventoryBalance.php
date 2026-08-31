<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Enums\ReservationStatus;
use App\Modules\Offers\Models\Offer;
use Database\Factories\InventoryBalanceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The physical count for one offer at one location.
 *
 * on_hand changes only through a movement. `available` is never a column —
 * it is on_hand minus live reservations, derived every time it is asked for.
 */
final class InventoryBalance extends Model
{
    /** @use HasFactory<InventoryBalanceFactory> */
    use HasFactory;

    protected $table = 'inventory_balances';

    protected $fillable = ['offer_id', 'inventory_location_id', 'on_hand'];

    protected function casts(): array
    {
        return ['on_hand' => 'integer'];
    }

    /** @return BelongsTo<Offer, $this> */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function held(): int
    {
        return (int) InventoryReservation::query()
            ->where('offer_id', $this->offer_id)
            ->where('inventory_location_id', $this->inventory_location_id)
            ->where('status', ReservationStatus::Held->value)
            ->sum('quantity');
    }

    /** What the storefront may sell. */
    public function available(): int
    {
        return $this->on_hand - $this->held();
    }

    public function isLowStock(): bool
    {
        $threshold = (int) config('veritas.inventory.low_stock_threshold');

        return $this->available() > 0 && $this->available() <= $threshold;
    }
}
