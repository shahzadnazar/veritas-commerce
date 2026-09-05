<?php

declare(strict_types=1);

namespace App\Modules\Payouts\Data;

use App\Modules\Payouts\Enums\SettlementAttemptStatus;

/**
 * What a payout provider says about one transfer, in the platform's words.
 *
 * Deliberately not a provider object. When a Stripe Connect adapter is
 * written it will translate a Transfer into this, and nothing in the
 * payout domain will learn that Connect exists.
 */
final readonly class ProviderTransfer
{
    public function __construct(
        public string $provider,
        public string $reference,
        public SettlementAttemptStatus $status,
        public int $amountMinor,
        public string $currency,
        public ?string $failureCode = null,
        public ?string $failureMessage = null,
    ) {}

    public function succeeded(): bool
    {
        return $this->status === SettlementAttemptStatus::Succeeded;
    }
}
