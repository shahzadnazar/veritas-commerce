<?php

declare(strict_types=1);

namespace App\Modules\Payments\Exceptions;

use RuntimeException;

/**
 * The provider says a payment succeeded, and it does not match the order.
 *
 * §17. A wrong amount, a wrong currency, or a reference belonging to a
 * different attempt: each of these means something is broken between the
 * platform and the provider, and the safe response is to refuse to
 * transition anything and make the discrepancy visible to an operator.
 *
 * Marking the order paid anyway — on the grounds that money did seem to
 * arrive — is how a marketplace ships goods for the wrong price and only
 * discovers it at month end.
 */
final class PaymentVerificationFailed extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reason,
        /** @var array<string, scalar|null> Safe diagnostic values, no secrets. */
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public static function amountMismatch(int $expected, int $actual): self
    {
        return new self(
            "Provider reported {$actual} minor units against an order of {$expected}.",
            'amount_mismatch',
            ['expected_minor' => $expected, 'provider_minor' => $actual],
        );
    }

    public static function currencyMismatch(string $expected, string $actual): self
    {
        return new self(
            "Provider reported {$actual} against an order in {$expected}.",
            'currency_mismatch',
            ['expected' => $expected, 'provider' => $actual],
        );
    }

    public static function referenceMismatch(string $expected, string $actual): self
    {
        return new self(
            'The provider payment does not belong to this attempt.',
            'reference_mismatch',
            ['expected' => $expected, 'provider' => $actual],
        );
    }

    /** §23: money arrived for an order the platform has already closed. */
    public static function orderNoLongerOpen(string $status): self
    {
        return new self(
            "Payment succeeded for an order that is already {$status}.",
            'order_not_open',
            ['order_status' => $status],
        );
    }
}
