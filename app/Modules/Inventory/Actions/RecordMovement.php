<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Enums\InventoryMovementReason;
use App\Modules\Inventory\Enums\StockState;
use App\Modules\Inventory\Events\InventoryAdjusted;
use App\Modules\Inventory\Events\InventoryDepleted;
use App\Modules\Inventory\Events\InventoryLow;
use App\Modules\Inventory\Events\InventoryRestored;
use App\Modules\Inventory\Exceptions\InvalidStockOperation;
use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Inventory\Models\InventoryMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

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
 *
 * It is also where the rest of the system finds out. Every path that
 * changes stock — a seller's correction, a hold, a release, a sale —
 * arrives here, so announcing from here is the only way to be sure the
 * search index and the seller's notifications cannot miss one. Events go
 * out after commit, so nothing reacts to a state a rollback removed.
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

            $before = $locked->state();

            $locked->forceFill(['on_hand' => $onHand, 'reserved' => $reserved])->save();

            $this->announce($locked, $before);

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

    /**
     * Tells the rest of the system what just happened to availability.
     *
     * `InventoryAdjusted` fires for every movement, because anything that
     * moves `available` changes what a customer would see and the index
     * has to be rebuilt. The threshold events fire only on a crossing:
     * one that fired while stock merely *is* low would mail the seller on
     * every save.
     */
    private function announce(InventoryBalance $balance, StockState $before): void
    {
        $after = $balance->state();
        $available = $balance->on_hand - $balance->reserved;
        $offer = $balance->offer;

        if ($offer === null) {
            return;
        }

        $offerId = $offer->id;
        $productId = $offer->product_id;
        $sellerId = $offer->seller_account_id;

        DB::afterCommit(function () use ($offerId, $productId, $sellerId, $before, $after, $available): void {
            Event::dispatch(new InventoryAdjusted(
                offerId: $offerId,
                productId: $productId,
                sellerAccountId: $sellerId,
                from: $before,
                to: $after,
                available: $available,
            ));

            if ($before === $after) {
                return;
            }

            Event::dispatch(match ($after) {
                StockState::OutOfStock => new InventoryDepleted($offerId, $sellerId, $available),
                StockState::LowStock => new InventoryLow($offerId, $sellerId, $available),
                StockState::InStock => new InventoryRestored($offerId, $sellerId, $available),
            });
        });
    }
}
