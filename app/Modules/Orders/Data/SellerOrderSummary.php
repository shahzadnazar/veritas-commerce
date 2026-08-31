<?php

declare(strict_types=1);

namespace App\Modules\Orders\Data;

/**
 * What another module is allowed to know about a seller's order.
 *
 * Crossing a module boundary means crossing it with a DTO, not with an
 * Eloquent model — so the Orders module can change its tables without
 * every consumer following.
 */
final readonly class SellerOrderSummary
{
    public function __construct(
        public string $reference,
        public string $customerName,
        public string $orderTotal,
        public string $sellerEarning,
        public string $status,
        public string $placedAt,
    ) {}
}
