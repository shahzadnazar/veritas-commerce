<?php

declare(strict_types=1);

namespace App\Modules\Payments\Events;

/**
 * An attempt to take money did not succeed. Dispatched after commit.
 *
 * Not the end of the order: §20 keeps a declined order payable with its
 * stock still held, because a declined card is a customer reaching for
 * another one. This event exists so that reach is measurable — a
 * marketplace that cannot see its decline rate cannot tell a provider
 * problem from a pricing one.
 *
 * The provider's code travels with it for the operator's benefit. Nothing
 * that consumes this event is allowed to show that code to a customer;
 * PaymentLanguage exists for what they read (§53).
 */
final readonly class PaymentFailed
{
    public function __construct(
        public int $marketplaceOrderId,
        public string $orderReference,
        public int $amountMinor,
        public string $currency,
        public ?string $failureCode = null,
        public ?int $userId = null,
    ) {}
}
