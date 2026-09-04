<?php

declare(strict_types=1);

namespace App\Modules\Payments\Data;

use App\Modules\Payments\Enums\PaymentAttemptStatus;

/**
 * What a provider gave back when asked to prepare a payment.
 *
 * Domain-neutral by construction. No Stripe object, no Stripe status
 * string, no SDK type — the adapter has already translated all of it, so
 * the application can be read without knowing which provider is behind it.
 *
 * `clientSecret` is the one field that exists only because browsers need
 * it: it authorises the customer's browser to talk to the provider about
 * this one payment and nothing else. It is never logged, never stored and
 * never shown to anyone but the customer whose payment it is.
 */
final readonly class PreparedPayment
{
    public function __construct(
        public string $provider,
        public string $reference,
        public PaymentAttemptStatus $status,
        public int $amountMinor,
        public string $currency,
        public ?string $clientSecret = null,
        public ?string $providerStatus = null,
    ) {}
}
