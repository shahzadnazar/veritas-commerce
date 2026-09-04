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
 *
 * It carries the per-seller breakdown rather than only the order id so a
 * listener never has to reach into another module's tables to use it:
 * confirming to sellers and recording a purchase in the behavioural
 * stream both need whose sale it was and for how much, and both are meant
 * to be readable from the event alone.
 */
final readonly class PaymentSucceeded
{
    /**
     * @param  array<int, array{sellerOrderId: int, sellerAccountId: int, valueMinor: int}>  $lines
     */
    public function __construct(
        public int $marketplaceOrderId,
        public string $orderReference,
        public int $amountMinor,
        public string $currency,
        public array $lines,
        /** The customer, when there was one. Guests check out too. */
        public ?int $userId = null,
    ) {}

    /** @return array<int, int> */
    public function sellerOrderIds(): array
    {
        return array_map(static fn (array $line): int => $line['sellerOrderId'], $this->lines);
    }
}
