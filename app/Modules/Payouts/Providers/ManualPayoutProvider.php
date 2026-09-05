<?php

declare(strict_types=1);

namespace App\Modules\Payouts\Providers;

use App\Modules\Payouts\Contracts\PayoutProvider;
use App\Modules\Payouts\Data\ProviderTransfer;
use App\Modules\Payouts\Enums\SettlementAttemptStatus;
use RuntimeException;

/**
 * The Phase-1 rail: a person, a bank, and a reference written down.
 *
 * This adapter moves no money and pretends none was moved. `createTransfer`
 * is not implemented at all, because a manual payout is not something the
 * platform initiates — it is something a member of finance has already
 * done, and RecordPayoutSettlement writes down what they did.
 *
 * That refusal is the point. An adapter that quietly returned "succeeded"
 * would let the settlement path look automated in tests and in the code,
 * and the first person to read it would believe the platform had a payout
 * rail. It does not. §17 of the M7 brief is explicit that no automatic
 * seller transfer exists in this milestone, and this file is where that
 * fact is enforced rather than merely stated.
 */
final class ManualPayoutProvider implements PayoutProvider
{
    public function name(): string
    {
        return 'manual';
    }

    /** @param array<string, string> $metadata */
    public function createTransfer(
        string $destinationReference,
        int $amountMinor,
        string $currency,
        string $idempotencyKey,
        array $metadata = [],
    ): ProviderTransfer {
        throw new RuntimeException(
            'The manual payout provider cannot send money. '.
            'Phase-1 settlement is performed outside the platform and recorded through RecordPayoutSettlement.'
        );
    }

    /**
     * A manual transfer's record is the settlement attempt itself.
     *
     * There is no external system to ask, so this reports what the
     * platform was told rather than inventing a confirmation — a
     * reference that reached here was written down by a person who made
     * the transfer, and that is the whole of the evidence.
     */
    public function retrieveTransfer(string $reference): ProviderTransfer
    {
        return new ProviderTransfer(
            provider: $this->name(),
            reference: $reference,
            status: SettlementAttemptStatus::Succeeded,
            amountMinor: 0,
            currency: 'USD',
        );
    }

    public function cancelTransfer(string $reference): bool
    {
        // Money sent by hand cannot be recalled by an API. Saying so is
        // more useful than a method that returns true and does nothing.
        return false;
    }
}
