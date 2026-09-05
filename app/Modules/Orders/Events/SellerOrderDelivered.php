<?php

declare(strict_types=1);

namespace App\Modules\Orders\Events;

use Illuminate\Support\Carbon;

/**
 * Everything the seller still owed the customer has arrived.
 *
 * The most consequential event in M6: it is what starts the seller's
 * earnings clearing, and it must fire exactly once per seller order. Its
 * "exactly once" comes from the state transition it follows — a seller
 * order enters DELIVERED once, under a row lock, and the enum has no route
 * back — rather than from a flag anybody has to remember to check.
 *
 * One parcel arriving is not this. A seller order with two boxes, one
 * delivered, is PARTIALLY_DELIVERED and this has not fired.
 */
final readonly class SellerOrderDelivered
{
    public function __construct(
        public int $sellerOrderId,
        public string $sellerOrderReference,
        public int $sellerAccountId,
        public int $marketplaceOrderId,
        public string $orderReference,
        public Carbon $deliveredAt,
    ) {}
}
