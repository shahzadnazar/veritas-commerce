<?php

declare(strict_types=1);

namespace App\Modules\Payouts\Exceptions;

use App\Modules\Payouts\Data\PayoutEligibility;
use App\Modules\Payouts\Enums\PayoutIneligibility;
use App\Modules\Payouts\Enums\PayoutStatus;
use App\Modules\Sellers\Enums\SellerStatus;
use App\Support\Money;
use RuntimeException;

/**
 * A payout operation the domain refused.
 *
 * Carries a machine-readable reason alongside the sentence, so a
 * controller can map it to a status code and a screen can link to the
 * remedy without parsing English.
 */
final class PayoutNotPermitted extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reason,
        public readonly ?PayoutIneligibility $ineligibility = null,
    ) {
        parent::__construct($message);
    }

    /** The eligibility check refused it, with the wording the seller sees. */
    public static function ineligible(PayoutEligibility $eligibility): self
    {
        return new self(
            $eligibility->message,
            $eligibility->reason === null ? 'not_eligible' : $eligibility->reason->value,
            $eligibility->reason,
        );
    }

    public static function exceedsWithdrawable(int $requested, int $withdrawable, string $currency = 'USD'): self
    {
        return new self(
            sprintf(
                'Requested %s but only %s is available to withdraw.',
                Money::formatSigned($requested, $currency),
                Money::formatSigned($withdrawable, $currency),
            ),
            'exceeds_withdrawable',
        );
    }

    public static function belowMinimum(int $requested, int $minimum, string $currency = 'USD'): self
    {
        return new self(
            sprintf(
                'The minimum payout is %s; %s was requested.',
                Money::of($minimum, $currency)->format(),
                Money::formatSigned($requested, $currency),
            ),
            'below_minimum',
            PayoutIneligibility::BelowMinimum,
        );
    }

    public static function notPositive(int $requested, string $currency = 'USD'): self
    {
        return new self(
            sprintf('A payout must be for more than nothing; %s was requested.', Money::formatSigned($requested, $currency)),
            'amount_not_positive',
        );
    }

    public static function alreadyOpen(): self
    {
        return new self(
            'This store already has an open payout request. Wait for it to be decided, or cancel it first.',
            'already_open',
            PayoutIneligibility::OpenPayoutExists,
        );
    }

    public static function sellerNotEligible(SellerStatus $status): self
    {
        return new self(
            "A {$status->label()} store cannot request a payout.",
            'seller_not_eligible',
            PayoutIneligibility::SellerNotEligible,
        );
    }

    public static function invalidTransition(PayoutStatus $from, PayoutStatus $to): self
    {
        return new self(
            "A {$from->label()} payout cannot become {$to->label()}.",
            'invalid_transition',
        );
    }

    public static function reasonRequired(string $what): self
    {
        return new self(
            "A reason is required to {$what} a payout. It is shown to the seller.",
            'reason_required',
        );
    }

    public static function notSettleable(PayoutStatus $status): self
    {
        return new self(
            "A {$status->label()} payout cannot be settled. Only an approved or processing payout can.",
            'not_settleable',
        );
    }

    public static function settlementReferenceRequired(): self
    {
        return new self(
            'Record the reference the transfer was made under. It is the only link between this payout and the money that moved.',
            'settlement_reference_required',
        );
    }

    public static function notCancellable(PayoutStatus $status): self
    {
        return new self(
            "A {$status->label()} payout can no longer be cancelled by the store. Ask support to reject it instead.",
            'not_cancellable',
        );
    }

    public static function currencyMismatch(string $expected, string $actual): self
    {
        return new self(
            "This payout is in {$expected}; {$actual} was given.",
            'currency_mismatch',
        );
    }

    /** Not enough available earnings to allocate against — see AllocatePayoutFunds. */
    public static function insufficientAllocatableFunds(int $requested, int $allocatable, string $currency = 'USD'): self
    {
        return new self(
            sprintf(
                'Only %s of available earnings can be allocated to a %s payout.',
                Money::formatSigned($allocatable, $currency),
                Money::formatSigned($requested, $currency),
            ),
            'insufficient_allocatable_funds',
        );
    }
}
