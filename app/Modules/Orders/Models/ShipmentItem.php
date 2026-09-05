<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Which order item, and how many of it, are in this parcel.
 *
 * The row that makes a partial shipment expressible. Without it a shipment
 * is only a status change, "what is left to send" has to be inferred from
 * a status column, and a customer who ordered three things and received
 * one is told their order shipped.
 *
 * APPEND ONLY once the parcel leaves. While a shipment is still being made
 * up its contents may change; from the moment it is shipped, what was in
 * the box is a historical fact.
 *
 * @property int $id
 * @property int $shipment_id
 * @property int $order_item_id
 * @property int $quantity
 * @property Carbon $created_at
 * @property-read Shipment|null $shipment
 * The column is NOT NULL and the foreign key restricts deletes, so a
 * shipment item always has its order item.
 * @property-read OrderItem $orderItem
 */
final class ShipmentItem extends Model
{
    protected $table = 'shipment_items';

    public $timestamps = false;

    protected $fillable = ['shipment_id', 'order_item_id', 'quantity', 'created_at'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        $guard = static function (self $item): void {
            $shipment = $item->shipment;

            if ($shipment !== null && ! $shipment->status->contentsAreMutable()) {
                throw new RuntimeException(
                    "Shipment {$shipment->reference} has already left; what was in the box cannot change."
                );
            }
        };

        self::updating($guard);
        self::deleting($guard);
    }

    /** @return BelongsTo<Shipment, $this> */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    /** @return BelongsTo<OrderItem, $this> */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
