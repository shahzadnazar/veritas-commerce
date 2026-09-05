<?php

declare(strict_types=1);

namespace App\Modules\Orders\Events;

/**
 * A parcel left the seller. Dispatched after the transaction commits.
 *
 * Carries what the customer needs to be told, so a listener never has to
 * reach back into another module's tables to write the message.
 */
final readonly class ShipmentShipped
{
    /** @param array<int, array{title: string, quantity: int}> $items */
    public function __construct(
        public int $shipmentId,
        public string $shipmentReference,
        public int $sellerOrderId,
        public string $sellerOrderReference,
        public int $marketplaceOrderId,
        public string $orderReference,
        public string $storeName,
        public ?string $carrierName,
        public ?string $trackingNumber,
        public ?string $trackingUrl,
        public array $items,
        public ?int $customerUserId = null,
        public string $customerEmail = '',
    ) {}
}
