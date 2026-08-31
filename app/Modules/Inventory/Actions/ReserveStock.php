<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Enums\ReservationStatus;
use App\Modules\Inventory\Exceptions\InsufficientStock;
use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Inventory\Models\InventoryReservation;
use Illuminate\Support\Facades\DB;

/**
 * Holds stock for a checkout without changing the physical count.
 *
 * Two things make this safe under concurrency:
 *
 *  1. The balance rows are locked FOR UPDATE before availability is read,
 *     so two simultaneous checkouts cannot both see the last unit.
 *  2. Locks are taken in ascending offer_id order, so two carts containing
 *     the same offers in different sequences cannot deadlock.
 *
 * Nothing here calls a payment provider: the transaction commits first, and
 * the network call happens outside it.
 */
final class ReserveStock
{
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
                $balance = $balances[$offerId] ?? null;

                if ($balance === null) {
                    throw new InsufficientStock($offerId, $quantity, 0);
                }

                $held = (int) InventoryReservation::query()
                    ->where('offer_id', $offerId)
                    ->where('inventory_location_id', $balance->inventory_location_id)
                    ->where('status', ReservationStatus::Held->value)
                    ->sum('quantity');

                $available = $balance->on_hand - $held;

                if ($available < $quantity) {
                    throw new InsufficientStock($offerId, $quantity, max(0, $available));
                }

                $reservations[] = InventoryReservation::create([
                    'offer_id' => $offerId,
                    'inventory_location_id' => $balance->inventory_location_id,
                    'quantity' => $quantity,
                    'status' => ReservationStatus::Held->value,
                    'reference' => $reference,
                    'expires_at' => now()->addMinutes($ttlMinutes),
                ]);
            }

            return $reservations;
        });
    }
}
