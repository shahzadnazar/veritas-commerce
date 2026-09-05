<?php

declare(strict_types=1);

namespace Tests\Feature\Fulfilment;

use App\Modules\Orders\Actions\AcknowledgeSellerOrder;
use App\Modules\Orders\Actions\CreateShipment;
use App\Modules\Orders\Actions\MarkShipmentDelivered;
use App\Modules\Orders\Actions\MarkShipmentShipped;
use App\Modules\Orders\Data\ShipmentTracking;
use App\Modules\Orders\Enums\SellerOrderStatus;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\SellerOrder;
use App\Modules\Orders\Models\Shipment;

/**
 * Paid seller orders and the parcels that leave them, built through the
 * real actions.
 *
 * Nothing here inserts a shipment row or moves a status by hand: a fixture
 * that did could satisfy every assertion while the real path produced
 * something different, which is exactly the bug these tests exist to
 * catch.
 */
trait BuildsFulfilableOrders
{
    protected function sellerOrderFor(int $marketplaceOrderId, ?int $sellerAccountId = null): SellerOrder
    {
        $query = SellerOrder::query()
            ->withoutGlobalScopes()
            ->where('marketplace_order_id', $marketplaceOrderId);

        if ($sellerAccountId !== null) {
            $query->where('seller_account_id', $sellerAccountId);
        }

        return $query->orderBy('position')->firstOrFail();
    }

    /** @return array<int, OrderItem> */
    protected function itemsOf(SellerOrder $sellerOrder): array
    {
        return OrderItem::query()
            ->where('seller_order_id', $sellerOrder->id)
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * A parcel holding the given items, with tracking ready to go.
     *
     * @param  array<int, array{order_item_id: int, quantity: int}>  $lines
     */
    protected function shipmentFor(SellerOrder $sellerOrder, array $lines, string $carrier = 'usps'): Shipment
    {
        if ($sellerOrder->status === SellerOrderStatus::Paid) {
            $this->confirm($sellerOrder);
        }

        return app(CreateShipment::class)(
            $sellerOrder,
            $lines,
            ShipmentTracking::of($carrier, '9400100000012345678901'),
        );
    }

    /** The seller acknowledges the order, as they must before packing it. */
    protected function confirm(SellerOrder $sellerOrder): void
    {
        app(AcknowledgeSellerOrder::class)->confirm($sellerOrder);
    }

    /** Everything still owed, in one parcel, sent. */
    protected function shipEverything(SellerOrder $sellerOrder): Shipment
    {
        if ($sellerOrder->status === SellerOrderStatus::Paid) {
            $this->confirm($sellerOrder);
        }

        $lines = [];

        foreach ($this->itemsOf($sellerOrder) as $item) {
            $lines[] = ['order_item_id' => (int) $item->id, 'quantity' => $item->quantity];
        }

        $shipment = $this->shipmentFor($sellerOrder, $lines);
        app(MarkShipmentShipped::class)($shipment);

        return $shipment->refresh();
    }

    protected function deliver(Shipment $shipment): void
    {
        app(MarkShipmentDelivered::class)($shipment);
    }
}
