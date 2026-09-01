<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Enums\InventoryMovementReason;
use App\Modules\Inventory\Exceptions\InvalidStockOperation;
use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Inventory\Models\InventoryMovement;
use Illuminate\Support\Facades\DB;

/**
 * The only way either stock quantity changes.
 *
 * Every write is double entry: a movement carries a delta for `on_hand` and
 * a delta for `reserved`, and both resulting values are stamped on the row.
 * Replaying an offer's movements from zero therefore reproduces both
 * columns exactly — the property `inventory:reconcile` and
 * InventoryLedgerTest assert, and the reason a stored `reserved` is safe to
 * read rather than a number that drifts.
 *
 * The balance row is locked FOR UPDATE before anything is read, so two
 * concurrent writers serialise instead of both deciding from the same
 * stale numbers. The database's CHECK constraints are the backstop: even a
 * caller that skipped this action cannot leave the row invalid.
 */
final class RecordMovement
{
    public function __invoke(
        InventoryBalance $balance,
        InventoryMovementReason $reason,
        int $onHandChange = 0,
        int $reservedChange = 0,
        string $actorType = 'system',
        ?int $actorId = null,
        ?string $note = null,
        ?int $sellerOrderId = null,
    ): InventoryMovement {
        if ($onHandChange === 0 && $reservedChange === 0) {
            throw new InvalidStockOperation('A movement that changes nothing is not a movement.');
        }

        return DB::transaction(function () use (
            $balance, $reason, $onHandChange, $reservedChange, $actorType, $actorId, $note, $sellerOrderId
        ): InventoryMovement {
            /** @var InventoryBalance $locked */
            $locked = InventoryBalance::query()
                ->whereKey($balance->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $onHand = $locked->on_hand + $onHandChange;
            $reserved = $locked->reserved + $reservedChange;

            /*
             * Checked here as well as by the database, because a caller
             * deserves a sentence it can show a seller rather than a
             * constraint-violation stack trace. The constraints remain the
             * thing that actually guarantees it.
             */
            if ($onHand < 0) {
                throw new InvalidStockOperation(
                    "That would take stock to {$onHand}. Stock cannot go negative."
                );
            }

            if ($reserved < 0) {
                throw new InvalidStockOperation(
                    "That would take reserved stock to {$reserved}, which would mean releasing a hold twice."
                );
            }

            if ($reserved > $onHand) {
                throw new InvalidStockOperation(
                    "That would reserve {$reserved} units of {$onHand} in stock. ".
                    'A reservation cannot promise units that are not held.'
                );
            }

            $locked->forceFill(['on_hand' => $onHand, 'reserved' => $reserved])->save();

            return InventoryMovement::query()->create([
                'offer_id' => $locked->offer_id,
                'inventory_location_id' => $locked->inventory_location_id,
                'on_hand_change' => $onHandChange,
                'reserved_change' => $reservedChange,
                'resulting_on_hand' => $onHand,
                'resulting_reserved' => $reserved,
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
