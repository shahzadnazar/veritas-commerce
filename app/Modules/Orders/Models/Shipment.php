<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use App\Support\HasPublicId;
use Database\Factories\ShipmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class Shipment extends Model
{
    /** @use HasFactory<ShipmentFactory> */
    use HasFactory;

    use HasPublicId;

    protected $table = 'shipments';

    protected $fillable = [
        'seller_order_id', 'carrier', 'carrier_code', 'tracking_number',
        'shipped_at', 'delivered_at',
    ];

    protected function casts(): array
    {
        return ['shipped_at' => 'datetime', 'delivered_at' => 'datetime'];
    }
}
