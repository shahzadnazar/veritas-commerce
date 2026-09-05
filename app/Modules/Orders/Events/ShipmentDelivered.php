<?php

declare(strict_types=1);

namespace App\Modules\Orders\Events;

/** A parcel arrived. Dispatched after the transaction commits. */
final readonly class ShipmentDelivered
{
    public function __construct(
        public int $shipmentId,
        public string $shipmentReference,
        public int $sellerOrderId,
        public string $sellerOrderReference,
        public string $orderReference,
        public string $storeName,
        public ?int $customerUserId = null,
        public string $customerEmail = '',
    ) {}
}
