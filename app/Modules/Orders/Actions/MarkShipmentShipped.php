<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Orders\Enums\SellerOrderStatus;
use App\Modules\Orders\Enums\ShipmentStatus;
use App\Modules\Orders\Events\ShipmentShipped;
use App\Modules\Orders\Exceptions\FulfilmentRefused;
use App\Modules\Orders\Models\MarketplaceOrder;
use App\Modules\Orders\Models\SellerOrder;
use App\Modules\Orders\Models\Shipment;
use App\Modules\Orders\Models\ShipmentItem;
use App\Modules\Orders\Models\ShipmentStatusHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Hands a parcel to the carrier.
 *
 * This is the moment the customer is told something is on its way, so the
 * requirements are the customer's: a carrier and a tracking number,
 * because "shipped" with neither is a status change dressed up as
 * information.
 *
 * IT DOES NOT TOUCH INVENTORY. §17, and it is worth being explicit about
 * because it looks like the moment stock should move: the units were sold
 * — reservation consumed, on_hand reduced — when the payment was verified
 * in M5. Debiting again here would take the same stock off the shelf
 * twice, and the seller would find their count short by exactly their
 * sales. Shipping is a fulfilment state, not a second sale.
 *
 * Idempotent: a parcel already gone is left alone rather than shipped
 * again, so a retried job or a double-clicked button sends one
 * notification.
 */
final class MarkShipmentShipped
{
    public function __construct(private readonly RecomputeSellerOrderFulfilment $recompute) {}

    /** @return bool whether this call was the one that sent it */
    public function __invoke(
        Shipment $shipment,
        string $actorType = 'seller',
        ?int $actorId = null,
    ): bool {
        $sent = DB::transaction(function () use ($shipment, $actorType, $actorId): ?ShipmentShipped {
            /** @var Shipment $locked */
            $locked = Shipment::query()->whereKey($shipment->getKey())->lockForUpdate()->firstOrFail();

            // Already on its way, or cancelled. Either way, not now.
            if ($locked->status->hasLeft()) {
                return null;
            }

            if ($locked->status === ShipmentStatus::Cancelled) {
                throw FulfilmentRefused::shipmentAlreadyGone();
            }

            /** @var SellerOrder $sellerOrder */
            $sellerOrder = SellerOrder::query()
                ->withoutGlobalScopes()
                ->whereKey($locked->seller_order_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($sellerOrder->status === SellerOrderStatus::PendingPayment) {
                throw FulfilmentRefused::notPaid();
            }

            if ($locked->items()->count() === 0) {
                throw FulfilmentRefused::nothingToShip();
            }

            if ($locked->carrier_name === null || $locked->tracking_number === null) {
                throw FulfilmentRefused::trackingRequired();
            }

            $from = $locked->status;

            $locked->forceFill([
                'status' => ShipmentStatus::Shipped->value,
                'packed_at' => $locked->packed_at ?? now(),
                'shipped_at' => now(),
            ])->save();

            ShipmentStatusHistory::query()->create([
                'shipment_id' => $locked->id,
                'from_status' => $from->value,
                'to_status' => ShipmentStatus::Shipped->value,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'carrier_name' => $locked->carrier_name,
                'tracking_number' => $locked->tracking_number,
                'created_at' => now(),
            ]);

            // The seller order's own state follows the parcels, not the
            // other way round: one of three boxes leaving is
            // PARTIALLY_SHIPPED, and only the last one is SHIPPED.
            ($this->recompute)($sellerOrder, actorType: $actorType);

            $shipment->setRawAttributes($locked->getAttributes(), true);

            return $this->announcement($locked, $sellerOrder);
        });

        if ($sent === null) {
            return false;
        }

        DB::afterCommit(static fn () => Event::dispatch($sent));

        return true;
    }

    /** Everything the customer needs, gathered while the rows are to hand. */
    private function announcement(Shipment $shipment, SellerOrder $sellerOrder): ShipmentShipped
    {
        /** @var MarketplaceOrder $order */
        $order = MarketplaceOrder::query()->whereKey($sellerOrder->marketplace_order_id)->firstOrFail();

        $items = $shipment->items()
            ->with('orderItem')
            ->get()
            ->map(static fn (ShipmentItem $line): array => [
                'title' => $line->orderItem->product_title,
                'quantity' => $line->quantity,
            ])
            ->all();

        return new ShipmentShipped(
            shipmentId: $shipment->id,
            shipmentReference: $shipment->reference,
            sellerOrderId: $sellerOrder->id,
            sellerOrderReference: $sellerOrder->reference,
            marketplaceOrderId: $order->id,
            orderReference: $order->reference,
            storeName: $this->storeNameFor($sellerOrder),
            carrierName: $shipment->carrier_name,
            trackingNumber: $shipment->tracking_number,
            trackingUrl: $shipment->tracking_url,
            items: $items,
            customerUserId: $order->user_id,
            customerEmail: $order->email,
        );
    }

    private function storeNameFor(SellerOrder $sellerOrder): string
    {
        $name = DB::table('stores')->where('id', $sellerOrder->store_id)->value('name');

        return is_string($name) ? $name : 'Seller';
    }
}
