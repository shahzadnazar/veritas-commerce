<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Enums\InventoryMovementReason;
use App\Modules\Inventory\Enums\ReservationStatus;
use App\Modules\Inventory\Exceptions\InsufficientStock;
use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Inventory\Models\InventoryReservation;
use Illuminate\Support\Facades\DB;

/**
 * Holds stock for a checkout without changing the physical count.
 *
 * Three things make this safe under concurrency:
 *
 *  1. The balance rows are locked FOR UPDATE before availability is read,
 *     so two simultaneous checkouts cannot both see the last unit.
 *  2. Locks are taken in ascending offer_id order, so two carts containing
 *     the same offers in different sequences cannot deadlock.
 *  3. `reserved` is a real column with a CHECK that it never exceeds
 *     on_hand, so even a caller that bypassed this action cannot oversell.
 *
 * Availability is read from the locked row rather than re-summed from the
 * reservation table: the column is the authority, and the sum is what
 * `inventory:reconcile` checks it against.
 *
 * Nothing here calls a payment provider: the transaction commits first, and
 * the network call happens outside it.
 */
final class ReserveStock
{
    public function __construct(private readonly RecordMovement $recordMovement) {}

    /**
     * @param  array<int, int>  $quantitiesByOfferId
     * @return array<int, InventoryReservation>
     *
     * @throws InsufficientStock
     */
    public function __invoke(array $quantitiesByOfferId, string $reference, ?int $ttlMinutes = null): array
    {
        $ttlMinutes ??= (int) config('veritas.inventory.reservation_ttl_minutes');

        // Deterministic lock order — this is what makes deadlocks impossible.
        ksort($quantitiesByOfferId);

        return DB::transaction(function () use ($quantitiesByOfferId, $reference, $ttlMinutes): array {
            $offerIds = array_keys($quantitiesByOfferId);

            /** @var array<int, InventoryBalance> $balances */
            $balances = InventoryBalance::query()
                ->whereIn('offer_id', $offerIds)
                ->orderBy('offer_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('offer_id')
                ->all();

            $reservations = [];

            foreach ($quantitiesByOfferId as $offerId => $quantity) {
                if ($quantity < 1) {
                    throw new InsufficientStock($offerId, $quantity, 0);
                }

                $balance = $balances[$offerId] ?? null;

                if ($balance === null) {
                    // No balance row means nothing was ever stocked here.
                    throw new InsufficientStock($offerId, $quantity, 0);
                }

                $available = $balance->on_hand - $balance->reserved;

                if ($available < $quantity) {
                    throw new InsufficientStock($offerId, $quantity, max(0, $available));
                }

                $reservation = InventoryReservation::query()->create([
                    'offer_id' => $offerId,
                    'inventory_location_id' => $balance->inventory_location_id,
                    'quantity' => $quantity,
                    'status' => ReservationStatus::Held->value,
                    'reference' => $reference,
                    'expires_at' => now()->addMinutes($ttlMinutes),
                ]);

                // The hold is a movement: `available` just dropped, and the
                // ledger has to be able to say why when nothing sold.
                $movement = ($this->recordMovement)(
                    balance: $balance,
                    reason: InventoryMovementReason::OrderReservation,
                    reservedChange: $quantity,
                    note: 'Reference '.$reference,
                );

                $reservation->forceFill(['opened_by_movement_id' => $movement->id])->save();

                $reservations[] = $reservation;
            }

            return $reservations;
        });
    }
}
