<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Orders\Enums\SellerOrderStatus;
use App\Modules\Orders\Models\SellerOrder;
use App\Modules\Orders\Queries\FulfilmentQuantities;
use Illuminate\Support\Facades\DB;

/**
 * Derives a seller order's fulfilment state from what is actually in the
 * parcels.
 *
 * §22, and it is the rule the whole aggregate turns on: a seller order is
 * not delivered because a shipment was delivered. An order of two items
 * sent in two boxes, one of which arrived, is PARTIALLY_DELIVERED — and a
 * screen that said DELIVERED would start the seller's earnings clearing
 * for goods the customer has not received.
 *
 * Nobody sets these four states by hand. Marking a parcel shipped or
 * delivered calls this, and this reads the item counts and decides. That
 * is what keeps one calculation in one place across three surfaces and two
 * scheduled jobs.
 *
 * Refunded units are excluded from the denominator throughout: an order
 * whose remaining item was refunded before it shipped is fully delivered
 * once everything still owed has arrived, and holding it open forever
 * waiting for a unit nobody owes anybody is not a state to leave a seller
 * in.
 */
final class RecomputeSellerOrderFulfilment
{
    public function __construct(
        private readonly FulfilmentQuantities $quantities,
        private readonly AdvanceSellerOrder $advance,
    ) {}

    /**
     * @return SellerOrderStatus|null the state it moved to, or null if it
     *                                did not move — which is what makes
     *                                "exactly once on delivery" true for
     *                                every caller rather than each one
     *                                remembering to check.
     */
    public function __invoke(SellerOrder $sellerOrder, string $actorType = 'system'): ?SellerOrderStatus
    {
        return DB::transaction(function () use ($sellerOrder, $actorType): ?SellerOrderStatus {
            /** @var SellerOrder $locked */
            $locked = SellerOrder::query()
                ->withoutGlobalScopes()
                ->whereKey($sellerOrder->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * A cancelled, refunded or disputed order is not walked
             * forward by parcel arithmetic. Those states are decisions
             * somebody made, and a delivery landing afterwards does not
             * undo them.
             */
            if (! $locked->status->isActionable() || $locked->status === SellerOrderStatus::Disputed) {
                return null;
            }

            $target = $this->targetFor($locked);

            if ($target === null || $target === $locked->status) {
                return null;
            }

            $moved = ($this->advance)($locked, $target, actorType: $actorType, note: 'Derived from shipment contents.');

            $sellerOrder->setRawAttributes($locked->refresh()->getAttributes(), true);

            return $moved ? $target : null;
        });
    }

    /**
     * The state the parcels say this order is in.
     *
     * Delivery is checked before dispatch: an order where everything has
     * arrived is delivered, and asking "has everything shipped" first
     * would answer the less specific question.
     */
    private function targetFor(SellerOrder $sellerOrder): ?SellerOrderStatus
    {
        $items = $this->quantities->forSellerOrder($sellerOrder);

        if ($items === []) {
            return null;
        }

        $fulfilable = 0;
        $shipped = 0;
        $delivered = 0;

        foreach ($items as $item) {
            $fulfilable += $item->fulfilable();
            $shipped += min($item->shipped, $item->fulfilable());
            $delivered += min($item->delivered, $item->fulfilable());
        }

        // Everything was refunded before anything left. There is nothing
        // to fulfil, and the refund domain owns what the order becomes.
        if ($fulfilable === 0) {
            return null;
        }

        return match (true) {
            $delivered >= $fulfilable => SellerOrderStatus::Delivered,
            $delivered > 0 => SellerOrderStatus::PartiallyDelivered,
            $shipped >= $fulfilable => SellerOrderStatus::Shipped,
            $shipped > 0 => SellerOrderStatus::PartiallyShipped,
            // Nothing has left yet; whatever preparation state the seller
            // put it in stands.
            default => null,
        };
    }
}
