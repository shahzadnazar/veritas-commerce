<?php

declare(strict_types=1);

namespace App\Modules\Payments\Events;

/**
 * A verified payment finalized. Dispatched after the transaction commits.
 *
 * Scalars, and after commit, both deliberately. A notification queued
 * inside the transaction that records the payment would go out even if
 * that transaction rolled back — telling a customer their order was paid
 * when the database has no record of it.
 */
final readonly class PaymentSucceeded
{
    /** @param array<int, int> $sellerOrderIds */
    public function __construct(
        public int $marketplaceOrderId,
        public string $orderReference,
        public int $amountMinor,
        public string $currency,
        public array $sellerOrderIds,
    ) {}
}
