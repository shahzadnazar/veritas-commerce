<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Orders\Enums\SellerOrderStatus;
use App\Modules\Orders\Exceptions\FulfilmentRefused;
use App\Modules\Orders\Models\OrderStatusHistory;
use App\Modules\Orders\Models\SellerOrder;
use Illuminate\Support\Facades\DB;

/**
 * The only way a seller order changes state.
 *
 * The state machine is on the enum; this is what makes it binding. Every
 * confirm, process, pack and aggregate recalculation comes through here,
 * so an illegal move — `pending_payment` straight to `shipped`,
 * `delivered` back to `processing`, anything at all out of `refunded` —
 * fails on the server rather than depending on a screen not offering the
 * button.
 *
 * Repeating a transition is not an error and not a second history row: a
 * double-clicked "Confirm" is one confirmation. That is what makes the
 * fulfilment actions safe to retry, which they must be, because the events
 * that follow them are queued.
 *
 * The timestamps are set here too, from one map, so a state and its date
 * cannot disagree.
 */
final class AdvanceSellerOrder
{
    /** @return bool whether this call was the one that moved it */
    public function __invoke(
        SellerOrder $sellerOrder,
        SellerOrderStatus $to,
        string $actorType = 'seller',
        ?int $actorId = null,
        ?string $note = null,
    ): bool {
        return DB::transaction(function () use ($sellerOrder, $to, $actorType, $actorId, $note): bool {
            /** @var SellerOrder $locked */
            $locked = SellerOrder::query()
                ->withoutGlobalScopes()
                ->whereKey($sellerOrder->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $from = $locked->status;

            // Already there. Somebody pressed the button twice, or two
            // shipments finished together and both recalculated.
            if ($from === $to) {
                return false;
            }

            if (! in_array($to, $from->allowedTransitions(), true)) {
                throw FulfilmentRefused::invalidTransition($from->value, $to->value);
            }

            $locked->forceFill(array_merge(
                ['status' => $to->value],
                $this->stampFor($to),
            ))->save();

            OrderStatusHistory::query()->create([
                'seller_order_id' => $locked->id,
                'from_status' => $from->value,
                'to_status' => $to->value,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'note' => $note,
                'created_at' => now(),
            ]);

            $sellerOrder->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
    }

    /**
     * The date that goes with the state.
     *
     * A partial state deliberately stamps nothing: `shipped_at` means the
     * order finished shipping, and setting it when the first of three
     * parcels left would make every report about dispatch times wrong.
     *
     * @return array<string, mixed>
     */
    private function stampFor(SellerOrderStatus $to): array
    {
        return match ($to) {
            SellerOrderStatus::Confirmed => ['confirmed_at' => now()],
            SellerOrderStatus::Processing => ['processing_at' => now()],
            SellerOrderStatus::Packed => ['packed_at' => now()],
            SellerOrderStatus::Shipped => ['shipped_at' => now()],
            SellerOrderStatus::Delivered => ['delivered_at' => now()],
            SellerOrderStatus::Completed => ['completed_at' => now()],
            SellerOrderStatus::Cancelled => ['cancelled_at' => now()],
            default => [],
        };
    }
}
