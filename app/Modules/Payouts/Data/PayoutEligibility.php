<?php

declare(strict_types=1);

namespace App\Modules\Payouts\Data;

use App\Modules\Payouts\Enums\PayoutIneligibility;
use App\Support\Money;

/**
 * Whether this seller may request a payout right now, and if not, why.
 *
 * Computed on the server and handed to the browser as an answer, never as
 * ingredients. §17 and §73: the React side renders `canRequest` and
 * `message`; it does not compare a balance to a minimum, subtract a
 * reservation, or check for an open request. Every one of those rules
 * lives in exactly one place, and this is the shape it leaves in.
 */
final readonly class PayoutEligibility
{
    public function __construct(
        public bool $canRequest,
        public ?PayoutIneligibility $reason,
        public string $message,
        public int $withdrawableMinor,
        public int $minimumMinor,
        public string $currency,
        /** The reference of the request already open, when one is. */
        public ?string $openPayoutReference = null,
    ) {}

    public static function allowed(int $withdrawableMinor, int $minimumMinor, string $currency): self
    {
        return new self(
            canRequest: true,
            reason: null,
            message: 'You can request a payout of up to '.Money::formatSigned($withdrawableMinor, $currency).'.',
            withdrawableMinor: $withdrawableMinor,
            minimumMinor: $minimumMinor,
            currency: $currency,
        );
    }

    public static function refused(
        PayoutIneligibility $reason,
        int $withdrawableMinor,
        int $minimumMinor,
        string $currency,
        ?string $openPayoutReference = null,
    ): self {
        return new self(
            canRequest: false,
            reason: $reason,
            message: $reason->message(
                $reason === PayoutIneligibility::BelowMinimum
                    ? Money::of($minimumMinor, $currency)->format()
                    : null,
            ),
            withdrawableMinor: $withdrawableMinor,
            minimumMinor: $minimumMinor,
            currency: $currency,
            openPayoutReference: $openPayoutReference,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'canRequest' => $this->canRequest,
            // The machine-readable reason travels too, so a screen can
            // link "add a payout destination" to the right page — but the
            // words a seller reads are the ones composed above.
            'reason' => $this->reason?->value,
            'message' => $this->message,
            'withdrawableMinor' => $this->withdrawableMinor,
            'withdrawable' => Money::formatSigned($this->withdrawableMinor, $this->currency),
            'minimumMinor' => $this->minimumMinor,
            'minimum' => Money::of($this->minimumMinor, $this->currency)->format(),
            'currency' => $this->currency,
            'openPayoutReference' => $this->openPayoutReference,
        ];
    }
}
