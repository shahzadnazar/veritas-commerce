<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Enums\InventoryMovementReason;
use App\Modules\Inventory\Enums\ReservationStatus;
use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Inventory\Models\InventoryReservation;
use Illuminate\Support\Facades\DB;

/**
 * Turns a hold into a sale once payment has captured.
 *
 * The hold is marked consumed and on_hand is decremented in the same
 * transaction, so availability never double-counts during the switch.
 */
final class ConsumeReservation
{
    public function __construct(private readonly RecordMovement $recordMovement) {}

    public function __invoke(string $reference, ?int $sellerOrderId = null): int
    {
        return DB::transaction(function () use ($reference, $sellerOrderId): int {
            $reservations = InventoryReservation::query()
                ->where('reference', $reference)
                ->where('status', ReservationStatus::Held->value)
                ->lockForUpdate()
                ->get();

            foreach ($reservations as $reservation) {
                $balance = InventoryBalance::query()
                    ->where('offer_id', $reservation->offer_id)
                    ->where('inventory_location_id', $reservation->inventory_location_id)
                    ->firstOrFail();

                $reservation->update([
                    'status' => ReservationStatus::Consumed->value,
                    'resolved_at' => now(),
                ]);

                ($this->recordMovement)(
                    balance: $balance,
                    change: -$reservation->quantity,
                    reason: InventoryMovementReason::SaleCompleted,
                    actorType: 'system',
                    sellerOrderId: $sellerOrderId,
                );
            }

            return $reservations->count();
        });
    }
}
