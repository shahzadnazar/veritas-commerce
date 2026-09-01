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
 * Both quantities fall in the same movement: the units leave the shelf and
 * stop being spoken for at the same instant. Doing it as one entry is what
 * stops availability flickering upward between two writes — with separate
 * movements there is a moment where the stock is released but not yet sold,
 * and a concurrent reservation could take it.
 *
 * IDEMPOTENT for the same reason release is: only held rows are selected,
 * under a lock, so a retried job commits the sale once.
 */
final class ConsumeReservation
{
    public function __construct(private readonly RecordMovement $recordMovement) {}

    public function __invoke(string $reference, ?int $sellerOrderId = null): int
    {
        return $this->consume($reference, static fn (): ?int => $sellerOrderId);
    }

    /**
     * The same sale, attributed line by line.
     *
     * A multi-seller order's holds all share one reference but belong to
     * different seller orders, and a movement filed against the wrong one
     * would misattribute the sale in every report downstream of it. The
     * caller supplies the map because it is the only thing that knows it.
     *
     * @param  array<int, int>  $sellerOrderIdByOfferId
     */
    public function attributed(string $reference, array $sellerOrderIdByOfferId): int
    {
        return $this->consume(
            $reference,
            static fn (int $offerId): ?int => $sellerOrderIdByOfferId[$offerId] ?? null,
        );
    }

    /**
     * @param  callable(int): ?int  $sellerOrderIdFor
     */
    private function consume(string $reference, callable $sellerOrderIdFor): int
    {
        return DB::transaction(function () use ($reference, $sellerOrderIdFor): int {
            $reservations = InventoryReservation::query()
                ->where('reference', $reference)
                ->where('status', ReservationStatus::Held->value)
                ->lockForUpdate()
                ->get();

            foreach ($reservations as $reservation) {
                $balance = InventoryBalance::query()
                    ->where('offer_id', $reservation->offer_id)
                    ->where('inventory_location_id', $reservation->inventory_location_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $movement = ($this->recordMovement)(
                    balance: $balance,
                    reason: InventoryMovementReason::SaleCompleted,
                    onHandChange: -$reservation->quantity,
                    reservedChange: -$reservation->quantity,
                    actorType: 'system',
                    sellerOrderId: $sellerOrderIdFor((int) $reservation->offer_id),
                );

                $reservation->forceFill([
                    'status' => ReservationStatus::Consumed->value,
                    'resolved_at' => now(),
                    'closed_by_movement_id' => $movement->id,
                ])->save();
            }

            return $reservations->count();
        });
    }
}
