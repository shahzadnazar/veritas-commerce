<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Enums\ReservationStatus;
use App\Modules\Inventory\Models\InventoryReservation;
use Illuminate\Support\Facades\DB;

/**
 * Returns held stock to availability.
 *
 * Called when a payment fails, a checkout is abandoned past its TTL, or an
 * order is cancelled before the sale was written. on_hand is untouched —
 * the units never physically moved, so there is no movement to record.
 */
final class ReleaseReservation
{
    /** @return int the number of reservations released */
    public function __invoke(string $reference, ReservationStatus $to = ReservationStatus::Released): int
    {
        return DB::transaction(function () use ($reference, $to): int {
            $reservations = InventoryReservation::query()
                ->where('reference', $reference)
                ->where('status', ReservationStatus::Held->value)
                ->lockForUpdate()
                ->get();

            foreach ($reservations as $reservation) {
                $reservation->update([
                    'status' => $to->value,
                    'resolved_at' => now(),
                ]);
            }

            return $reservations->count();
        });
    }
}
