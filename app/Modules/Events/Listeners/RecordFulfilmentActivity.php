<?php

declare(strict_types=1);

namespace App\Modules\Events\Listeners;

use App\Modules\Events\Actions\RecordInteraction;
use App\Modules\Events\Enums\InteractionEventType;
use App\Modules\Orders\Events\SellerOrderConfirmed;
use App\Modules\Orders\Events\SellerOrderDelivered;
use App\Modules\Orders\Events\ShipmentCreated;
use App\Modules\Orders\Events\ShipmentDelivered;
use App\Modules\Orders\Events\ShipmentShipped;

/**
 * Fulfilment, into the operational stream.
 *
 * How long a seller takes to confirm, to dispatch and to deliver is a
 * question about the marketplace that the order tables can answer only by
 * being aggregated repeatedly; this makes it a stream.
 *
 * §55 draws the line that matters: the stream is a record, never the
 * truth. Whether a parcel shipped is answered by the shipment table, and
 * nothing in the fulfilment domain reads back from here — an analytics row
 * that failed to write must never be able to change what a customer is
 * told about their order.
 */
final class RecordFulfilmentActivity
{
    public function __construct(private readonly RecordInteraction $interactions) {}

    public function confirmed(SellerOrderConfirmed $event): void
    {
        $this->record(InteractionEventType::OrderConfirmed, $event->sellerAccountId, [
            'seller_order' => $event->sellerOrderReference,
        ]);
    }

    public function shipmentCreated(ShipmentCreated $event): void
    {
        $this->record(InteractionEventType::ShipmentCreated, $event->sellerAccountId, [
            'shipment' => $event->shipmentReference,
        ]);
    }

    public function shipped(ShipmentShipped $event): void
    {
        $this->record(InteractionEventType::ShipmentShipped, null, [
            'shipment' => $event->shipmentReference,
            'order_reference' => $event->orderReference,
            'carrier' => $event->carrierName,
        ]);
    }

    public function shipmentDelivered(ShipmentDelivered $event): void
    {
        $this->record(InteractionEventType::ShipmentDelivered, null, [
            'shipment' => $event->shipmentReference,
            'order_reference' => $event->orderReference,
        ]);
    }

    public function orderDelivered(SellerOrderDelivered $event): void
    {
        $this->record(InteractionEventType::OrderDelivered, $event->sellerAccountId, [
            'seller_order' => $event->sellerOrderReference,
            'order_reference' => $event->orderReference,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function record(InteractionEventType $type, ?int $sellerAccountId, array $payload): void
    {
        $this->interactions->record(
            request(),
            $type,
            sellerAccountId: $sellerAccountId,
            payload: array_merge(['context' => 'fulfilment'], $payload),
        );
    }
}
