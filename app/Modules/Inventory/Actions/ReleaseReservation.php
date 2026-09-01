<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Enums\InventoryMovementReason;
use App\Modules\Inventory\Enums\ReservationStatus;
use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Inventory\Models\InventoryReservation;
use Illuminate\Support\Facades\DB;

/**
 * Returns held stock to availability.
 *
 * Called when a payment fails, a checkout is abandoned past its TTL, or an
 * order is cancelled before the sale was written. on_hand is untouched —
 * the units never physically moved — but `reserved` falls, and that is a
 * movement.
 *
 * IDEMPOTENT, which is the whole point: expiry sweeps are queued, jobs are
 * retried, and a reference released twice must restore stock once. Only
 * rows still in `held` are selected, and they are selected FOR UPDATE, so
 * two concurrent sweeps cannot both claim the same hold. A second call
 * finds nothing to do and returns zero.
 */
final class ReleaseReservation
{
    public function __construct(private readonly RecordMovement $recordMovement) {}

    /** @return int the number of reservations released */
    public function __invoke(string $reference, ReservationStatus $to = ReservationStatus::Released): int
    {
        return DB::transaction(function () use ($reference, $to): int {
            $reservations = InventoryReservation::query()
                ->where('reference', $reference)
                ->where('status', ReservationStatus::Held->value)
                ->lockForUpdate()
                ->get();

            $reason = $to === ReservationStatus::Expired
                ? InventoryMovementReason::ReservationExpired
                : InventoryMovementReason::ReservationRelease;

            foreach ($reservations as $reservation) {
                $balance = InventoryBalance::query()
                    ->where('offer_id', $reservation->offer_id)
                    ->where('inventory_location_id', $reservation->inventory_location_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $movement = ($this->recordMovement)(
                    balance: $balance,
                    reason: $reason,
                    reservedChange: -$reservation->quantity,
                    note: 'Reference '.$reference,
                );

                $reservation->forceFill([
                    'status' => $to->value,
                    'resolved_at' => now(),
                    'closed_by_movement_id' => $movement->id,
                ])->save();
            }

            return $reservations->count();
        });
    }

    /** Releases one reservation by its public id, for operator-initiated cancels. */
    public function one(InventoryReservation $reservation, ReservationStatus $to = ReservationStatus::Released): bool
    {
        return DB::transaction(function () use ($reservation, $to): bool {
            /** @var InventoryReservation|null $locked */
            $locked = InventoryReservation::query()
                ->whereKey($reservation->getKey())
                ->where('status', ReservationStatus::Held->value)
                ->lockForUpdate()
                ->first();

            // Already resolved by an expiry sweep or a competing request.
            if ($locked === null) {
                return false;
            }

            $balance = InventoryBalance::query()
                ->where('offer_id', $locked->offer_id)
                ->where('inventory_location_id', $locked->inventory_location_id)
                ->lockForUpdate()
                ->firstOrFail();

            $movement = ($this->recordMovement)(
                balance: $balance,
                reason: $to === ReservationStatus::Expired
                    ? InventoryMovementReason::ReservationExpired
                    : InventoryMovementReason::ReservationRelease,
                reservedChange: -$locked->quantity,
            );

            $locked->forceFill([
                'status' => $to->value,
                'resolved_at' => now(),
                'closed_by_movement_id' => $movement->id,
            ])->save();

            return true;
        });
    }
}
