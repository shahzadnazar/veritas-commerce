<?php

declare(strict_types=1);

namespace App\Modules\Orders\Events;

/** Delivered, cleared, and closed. Dispatched after the transaction commits. */
final readonly class SellerOrderCompleted
{
    public function __construct(
        public int $sellerOrderId,
        public string $sellerOrderReference,
        public int $sellerAccountId,
        public int $marketplaceOrderId,
    ) {}
}
