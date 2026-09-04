<?php

declare(strict_types=1);

namespace App\Modules\Payments\Events;

/**
 * The provider reported something the platform will not act on.
 *
 * A wrong amount, a payment for an order already cancelled, a reference
 * belonging to a different attempt. §17 and §23: none of these may be
 * resolved automatically — money has moved and the platform's record
 * disagrees, which is an operational matter for a person, possibly ending
 * in a refund.
 *
 * Raised rather than thrown away so it reaches the admin surface and the
 * audit log instead of a log line nobody reads.
 */
final readonly class PaymentExceptionRaised
{
    /** @param array<string, scalar|null> $context */
    public function __construct(
        public string $reason,
        public string $providerReference,
        public ?int $marketplaceOrderId,
        public string $message,
        public array $context = [],
    ) {}
}
