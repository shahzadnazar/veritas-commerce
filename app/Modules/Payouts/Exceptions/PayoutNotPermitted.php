<?php

declare(strict_types=1);

namespace App\Modules\Payouts\Exceptions;

use App\Modules\Sellers\Enums\SellerStatus;
use App\Support\Money;
use RuntimeException;

final class PayoutNotPermitted extends RuntimeException
{
    public static function exceedsAvailable(int $requested, int $available): self
    {
        return new self(sprintf(
            'Requested %s but only %s is available to withdraw.',
            Money::of($requested)->format(),
            Money::of($available)->format(),
        ));
    }

    public static function belowMinimum(int $requested, int $minimum): self
    {
        return new self(sprintf(
            'The minimum payout is %s; %s was requested.',
            Money::of($minimum)->format(),
            Money::of($requested)->format(),
        ));
    }

    public static function alreadyOpen(): self
    {
        return new self('This store already has an open payout request. Wait for it to be decided, or cancel it first.');
    }

    public static function sellerNotEligible(SellerStatus $status): self
    {
        return new self("A {$status->label()} store cannot request a payout.");
    }
}
