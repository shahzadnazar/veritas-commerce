<?php

declare(strict_types=1);

namespace App\Modules\Payments\Data;

use App\Modules\Payments\Enums\PaymentAttemptStatus;

/**
 * The provider's own record of a payment, read back from the provider.
 *
 * This is the only thing the platform will believe about whether money
 * arrived. Not the browser, not a webhook payload taken at face value —
 * the provider's answer to "what is the state of this payment", fetched
 * over an authenticated connection, translated once, and checked against
 * the order before anything is transitioned.
 *
 * `capturedAmountMinor` is separate from `amountMinor` on purpose: a
 * provider can capture less than was authorised, and the amount that has
 * to match the order is the one that actually moved.
 */
final readonly class ProviderPayment
{
    /**
     * @param  array<string, string>  $metadata  what the platform put on the payment, echoed back
     */
    public function __construct(
        public string $provider,
        public string $reference,
        public PaymentAttemptStatus $status,
        public int $amountMinor,
        public string $currency,
        public ?int $capturedAmountMinor = null,
        public ?string $providerStatus = null,
        public ?string $chargeReference = null,
        public ?ProviderFailure $failure = null,
        public ?string $methodDescription = null,
        public array $metadata = [],
    ) {}

    /** What actually moved, which is what a payment is verified against. */
    public function settledAmountMinor(): int
    {
        return $this->capturedAmountMinor ?? $this->amountMinor;
    }
}
