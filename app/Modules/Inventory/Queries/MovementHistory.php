<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Queries;

use App\Modules\Inventory\Models\InventoryMovement;
use Illuminate\Support\Collection;

/**
 * One offer's stock history, as a person would read it.
 *
 * The ledger is double entry, so a row can move on_hand, reserved or both.
 * Presenting the raw pair would make a seller do the arithmetic; this says
 * what happened and what the numbers became.
 */
final class MovementHistory
{
    /** @return array<int, array<string, mixed>> */
    public function __invoke(int $offerId, int $limit = 100): array
    {
        /** @var Collection<int, InventoryMovement> $movements */
        $movements = InventoryMovement::query()
            ->where('offer_id', $offerId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return $movements
            ->map(fn (InventoryMovement $movement): array => [
                'publicId' => $movement->public_id,
                'reason' => $movement->reason->value,
                'reasonLabel' => $movement->reason->label(),
                'onHandChange' => $movement->on_hand_change,
                'reservedChange' => $movement->reserved_change,
                'resultingOnHand' => $movement->resulting_on_hand,
                'resultingReserved' => $movement->resulting_reserved,
                'resultingAvailable' => $movement->resulting_on_hand - $movement->resulting_reserved,
                'actorType' => $movement->actor_type,
                'actorId' => $movement->actor_id,
                'note' => $movement->note,
                'at' => $movement->created_at->toDayDateTimeString(),
            ])
            ->all();
    }
}
