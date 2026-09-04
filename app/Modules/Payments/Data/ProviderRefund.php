<?php

declare(strict_types=1);

namespace App\Modules\Payments\Data;

use App\Modules\Payments\Enums\RefundStatus;

/**
 * The provider's record of a refund.
 *
 * A refund is not instantaneous at every provider or for every payment
 * method, so this carries a status rather than a boolean: §44 turns on the
 * difference between "asked" and "left the account".
 */
final readonly class ProviderRefund
{
    public function __construct(
        public string $provider,
        public string $reference,
        public RefundStatus $status,
        public int $amountMinor,
        public string $currency,
        public ?string $providerStatus = null,
        public ?ProviderFailure $failure = null,
    ) {}
}
