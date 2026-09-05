<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use App\Modules\Orders\Enums\ShipmentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * APPEND ONLY. Every move a parcel made, and the tracking it carried when
 * it made it.
 *
 * "It says delivered" is not an answer in a dispute without when, by whom,
 * and from what — and a tracking number corrected after the fact must not
 * quietly replace the one the customer was originally given, or nobody can
 * reconstruct what they were told.
 *
 * @property int $id
 * @property int $shipment_id
 * @property ShipmentStatus|null $from_status
 * @property ShipmentStatus $to_status
 * @property string $actor_type
 * @property int|null $actor_id
 * @property string|null $reason
 * @property string|null $carrier_name
 * @property string|null $tracking_number
 * @property Carbon $created_at
 */
final class ShipmentStatusHistory extends Model
{
    protected $table = 'shipment_status_history';

    public $timestamps = false;

    protected $fillable = [
        'shipment_id', 'from_status', 'to_status', 'actor_type', 'actor_id',
        'reason', 'carrier_name', 'tracking_number', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'from_status' => ShipmentStatus::class,
            'to_status' => ShipmentStatus::class,
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new RuntimeException('shipment_status_history is append-only.');
        });

        self::deleting(function (): never {
            throw new RuntimeException('shipment_status_history is append-only.');
        });
    }
}
