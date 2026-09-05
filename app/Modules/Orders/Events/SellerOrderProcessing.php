<?php

declare(strict_types=1);

namespace App\Modules\Orders\Events;

/** Dispatched after the transaction commits. Scalars only. */
final readonly class SellerOrderProcessing
{
    public function __construct(
        public int $sellerOrderId,
        public string $sellerOrderReference,
        public int $sellerAccountId,
    ) {}
}
