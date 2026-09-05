<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Ledger\Actions\StartClearing;
use App\Modules\Orders\Enums\SellerOrderStatus;
use App\Modules\Orders\Enums\ShipmentStatus;
use App\Modules\Orders\Events\SellerOrderDelivered;
use App\Modules\Orders\Events\ShipmentDelivered;
use App\Modules\Orders\Exceptions\FulfilmentRefused;
use App\Modules\Orders\Models\MarketplaceOrder;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\SellerOrder;
use App\Modules\Orders\Models\Shipment;
use App\Modules\Orders\Models\ShipmentItem;
use App\Modules\Orders\Models\ShipmentStatusHistory;
use App\Modules\Orders\Support\ClearingPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Records that a parcel arrived.
 *
 * In Phase 1 a person says so — an authorised seller for their own
 * shipment, or an admin correcting one. There is no courier integration,
 * and a status that claimed to be carrier-verified when a seller typed it
 * would be worse than one that says who typed it. The UI says as much. A
 * future carrier webhook becomes another caller of this action, not a
 * rewrite of it.
 *
 * The customer never calls this. Visiting a tracking page is not delivery,
 * and making it so would let anyone start a seller's earnings clock by
 * refreshing a URL.
 *
 * Two things follow, and only when this call is the one that changes
 * something:
 *
 *  - the seller order's own state is recomputed from all its parcels, so
 *    one box arriving out of three is PARTIALLY_DELIVERED;
 *  - if that recomputation lands on DELIVERED, the clearing clock starts,
 *    exactly once, because a seller order enters that state once.
 */
final class MarkShipmentDelivered
{
    public function __construct(
        private readonly RecomputeSellerOrderFulfilment $recompute,
        private readonly ClearingPolicy $clearing,
        private readonly StartClearing $startClearing,
    ) {}

    /** @return bool whether this call was the one that recorded the arrival */
    public function __invoke(
        Shipment $shipment,
        string $actorType = 'seller',
        ?int $actorId = null,
        ?string $reason = null,
    ): bool {
        /** @var array{arrival: ShipmentDelivered, order: SellerOrderDelivered|null}|null $announcements */
        $announcements = DB::transaction(function () use ($shipment, $actorType, $actorId, $reason): ?array {
            /** @var Shipment $locked */
            $locked = Shipment::query()->whereKey($shipment->getKey())->lockForUpdate()->firstOrFail();

            // Already arrived. A retried job or a second click is not a
            // second delivery, and must not start a second clearing clock.
            if ($locked->status === ShipmentStatus::Delivered) {
                return null;
            }

            if (! $locked->status->hasLeft()) {
                // Nothing has been handed to a carrier yet.
                throw FulfilmentRefused::shipmentNotSent();
            }

            if ($locked->status === ShipmentStatus::Cancelled) {
                throw FulfilmentRefused::shipmentNotSent();
            }

            /** @var SellerOrder $sellerOrder */
            $sellerOrder = SellerOrder::query()
                ->withoutGlobalScopes()
                ->whereKey($locked->seller_order_id)
                ->lockForUpdate()
                ->firstOrFail();

            $from = $locked->status;

            $locked->forceFill([
                'status' => ShipmentStatus::Delivered->value,
                'delivered_at' => now(),
            ])->save();

            ShipmentStatusHistory::query()->create([
                'shipment_id' => $locked->id,
                'from_status' => $from->value,
                'to_status' => ShipmentStatus::Delivered->value,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'reason' => $reason,
                'carrier_name' => $locked->carrier_name,
                'tracking_number' => $locked->tracking_number,
                'created_at' => now(),
            ]);

            $this->creditDeliveredUnits($locked);

            $became = ($this->recompute)($sellerOrder, actorType: $actorType);

            /** @var MarketplaceOrder $order */
            $order = MarketplaceOrder::query()->whereKey($sellerOrder->marketplace_order_id)->firstOrFail();

            $orderDelivered = null;

            if ($became === SellerOrderStatus::Delivered) {
                /*
                 * The clearing deadline is written on the seller order
                 * inside this transaction, so the sweep can find its work
                 * with an index rather than by walking the ledger — and so
                 * an operator can see the date without opening a financial
                 * table.
                 */
                $fresh = $sellerOrder->refresh();
                $deliveredAt = $fresh->delivered_at ?? now();

                $availableAt = $this->clearing->availableAt($fresh, $deliveredAt);

                $fresh->forceFill(['earnings_clear_at' => $availableAt])->save();

                /*
                 * The money starts clearing in the same transaction as the
                 * delivery that justifies it. Not in a queued listener: a
                 * delivery recorded and a clock that failed to start is a
                 * seller whose money is stuck pending forever, and nothing
                 * would notice.
                 */
                ($this->startClearing)($fresh->id, $availableAt);

                $orderDelivered = new SellerOrderDelivered(
                    sellerOrderId: $fresh->id,
                    sellerOrderReference: $fresh->reference,
                    sellerAccountId: (int) $fresh->seller_account_id,
                    marketplaceOrderId: $order->id,
                    orderReference: $order->reference,
                    deliveredAt: $deliveredAt,
                );
            }

            $shipment->setRawAttributes($locked->getAttributes(), true);

            return [
                'arrival' => new ShipmentDelivered(
                    shipmentId: $locked->id,
                    shipmentReference: $locked->reference,
                    sellerOrderId: $sellerOrder->id,
                    sellerOrderReference: $sellerOrder->reference,
                    orderReference: $order->reference,
                    storeName: $this->storeNameFor($sellerOrder),
                    customerUserId: $order->user_id,
                    customerEmail: $order->email,
                ),
                'order' => $orderDelivered,
            ];
        });

        if ($announcements === null) {
            return false;
        }

        DB::afterCommit(static function () use ($announcements): void {
            Event::dispatch($announcements['arrival']);

            if ($announcements['order'] !== null) {
                Event::dispatch($announcements['order']);
            }
        });

        return true;
    }

    /**
     * The units in this parcel have arrived.
     *
     * Counted per order item so a seller order with two parcels can tell
     * "one box arrived" from "everything arrived" — which is the whole
     * difference between PARTIALLY_DELIVERED and starting a clearing clock.
     */
    private function creditDeliveredUnits(Shipment $shipment): void
    {
        /** @var iterable<int, ShipmentItem> $lines */
        $lines = ShipmentItem::query()->where('shipment_id', $shipment->id)->get();

        foreach ($lines as $line) {
            OrderItem::query()
                ->whereKey($line->order_item_id)
                ->incrementEach(['delivered_quantity' => $line->quantity]);
        }
    }

    private function storeNameFor(SellerOrder $sellerOrder): string
    {
        $name = DB::table('stores')->where('id', $sellerOrder->store_id)->value('name');

        return is_string($name) ? $name : 'Seller';
    }
}
