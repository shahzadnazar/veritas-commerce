<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Inventory\Enums\InventoryMovementReason;
use App\Modules\Inventory\Exceptions\InvalidStockOperation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Offers\Models\Offer;
use Illuminate\Support\Facades\DB;

/**
 * A person correcting stock by hand, with a reason.
 *
 * There is no silent stock edit anywhere in the system: the reason is a
 * typed enum, "Other" additionally requires words, and every adjustment
 * leaves a movement, an audit entry and an event behind. A count that
 * changed and nobody can say why is how a marketplace loses an argument
 * with a seller.
 *
 * The whole sequence is one transaction — validate, lock, apply, record,
 * audit. The events come from RecordMovement rather than from here,
 * because a hold or a sale changes availability too and the index must
 * not learn about stock from only one of the paths that moves it.
 *
 * Authorisation is NOT decided here. Whether this actor may touch this
 * seller's stock is a question about the request, answered by the
 * controller before the action is reached; what this owns is that the
 * resulting numbers are legal and the history is complete.
 */
final class AdjustInventory
{
    public function __construct(
        private readonly ResolveInventoryBalance $resolveBalance,
        private readonly RecordMovement $recordMovement,
        private readonly RecordAuditEvent $audit,
    ) {}

    /**
     * @param  int  $change  signed: the direction is the sign, the reason says why
     */
    public function __invoke(
        Offer $offer,
        int $change,
        InventoryMovementReason $reason,
        string $actorType,
        int $actorId,
        ?string $note = null,
    ): InventoryMovement {
        $note = $note === null ? null : trim($note);
        $note = $note === '' ? null : $note;

        if ($change === 0) {
            throw new InvalidStockOperation('An adjustment of zero changes nothing. Say how many units moved.');
        }

        if ($reason->requiresNote() && $note === null) {
            throw new InvalidStockOperation('“Other” explains nothing on its own. Say what happened.');
        }

        if ($reason->isReservationMovement()) {
            throw new InvalidStockOperation('Reservations are not adjusted by hand; they are held, released or sold.');
        }

        $balance = ($this->resolveBalance)($offer);

        return DB::transaction(function () use (
            $balance, $change, $reason, $actorType, $actorId, $note, $offer
        ): InventoryMovement {
            $movement = ($this->recordMovement)(
                balance: $balance,
                reason: $reason,
                onHandChange: $change,
                actorType: $actorType,
                actorId: $actorId,
                note: $note,
            );

            ($this->audit)(
                action: 'inventory.adjusted',
                actorType: $actorType,
                actorId: $actorId,
                subjectType: Offer::class,
                subjectId: $offer->id,
                changes: [
                    'reason' => $reason->value,
                    'change' => $change,
                    'on_hand' => ['from' => $movement->resulting_on_hand - $change, 'to' => $movement->resulting_on_hand],
                ],
                reason: $note,
            );

            return $movement;
        });
    }

    /**
     * Establishes a starting count through the ledger rather than around it.
     *
     * A seller activating their first offer has stock on a shelf already;
     * writing that number straight onto the balance would create the one
     * quantity in the system with no movement explaining it, and break the
     * replay invariant on day one.
     */
    public function openingStock(Offer $offer, int $quantity, string $actorType, int $actorId): InventoryMovement
    {
        if ($quantity < 1) {
            throw new InvalidStockOperation('Opening stock is how many units you have. Use an adjustment to correct it later.');
        }

        $balance = ($this->resolveBalance)($offer);

        if ($balance->on_hand !== 0 || InventoryMovement::query()->where('offer_id', $offer->id)->exists()) {
            throw new InvalidStockOperation(
                'This listing already has a stock history. Adjust the count instead of setting it again.'
            );
        }

        return $this($offer, $quantity, InventoryMovementReason::OpeningStock, $actorType, $actorId);
    }
}
