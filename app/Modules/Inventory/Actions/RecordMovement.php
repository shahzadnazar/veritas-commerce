<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Enums\InventoryMovementReason;
use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Inventory\Models\InventoryMovement;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The only way on_hand changes.
 *
 * Writes the movement and the resulting balance in one transaction, so
 * replaying every movement for an offer from zero always equals its current
 * on_hand — the property the nightly reconciliation and
 * InventoryMovementTest both assert.
 */
final class RecordMovement
{
    public function __invoke(
        InventoryBalance $balance,
        int $change,
        InventoryMovementReason $reason,
        string $actorType = 'system',
        ?int $actorId = null,
        ?string $note = null,
        ?int $sellerOrderId = null,
    ): InventoryMovement {
        return DB::transaction(function () use ($balance, $change, $reason, $actorType, $actorId, $note, $sellerOrderId): InventoryMovement {
            $locked = InventoryBalance::query()
                ->whereKey($balance->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $resulting = $locked->on_hand + $change;

            if ($resulting < 0) {
                throw new RuntimeException(
                    "Movement would take offer {$locked->offer_id} to {$resulting} on hand; stock cannot go negative."
                );
            }

            $locked->update(['on_hand' => $resulting]);

            return InventoryMovement::query()->create([
                'offer_id' => $locked->offer_id,
                'inventory_location_id' => $locked->inventory_location_id,
                'change' => $change,
                'resulting_on_hand' => $resulting,
                'reason' => $reason->value,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'note' => $note,
                'seller_order_id' => $sellerOrderId,
                'created_at' => now(),
            ]);
        });
    }
}
