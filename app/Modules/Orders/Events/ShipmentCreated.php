<?php

declare(strict_types=1);

namespace App\Modules\Orders\Events;

/** A parcel was made up. Dispatched after the transaction commits. */
final readonly class ShipmentCreated
{
    public function __construct(
        public int $shipmentId,
        public string $shipmentReference,
        public int $sellerOrderId,
        public int $sellerAccountId,
    ) {}
}
