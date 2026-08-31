<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Enums\InventoryMovementReason;
use App\Support\HasPublicId;
use Database\Factories\InventoryMovementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * APPEND ONLY.
 *
 * Replaying every movement for an offer from zero must equal its current
 * on_hand. The update and delete guards below make that a property of the
 * model rather than a convention people remember.
 */
final class InventoryMovement extends Model
{
    /** @use HasFactory<InventoryMovementFactory> */
    use HasFactory;

    use HasPublicId;

    protected $table = 'inventory_movements';

    public $timestamps = false;

    protected $fillable = [
        'offer_id',
        'inventory_location_id',
        'change',
        'resulting_on_hand',
        'reason',
        'actor_type',
        'actor_id',
        'note',
        'seller_order_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'reason' => InventoryMovementReason::class,
            'change' => 'integer',
            'resulting_on_hand' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new RuntimeException('inventory_movements is append-only; write a correcting movement instead.');
        });

        self::deleting(function (): never {
            throw new RuntimeException('inventory_movements is append-only and cannot be deleted.');
        });
    }
}
