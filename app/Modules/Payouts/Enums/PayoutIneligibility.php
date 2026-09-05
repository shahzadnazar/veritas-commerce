<?php

declare(strict_types=1);

namespace App\Modules\Payouts\Enums;

/**
 * Why a seller cannot request a payout right now.
 *
 * §18. A disabled button with no explanation is how a seller ends up
 * emailing support to ask where their money is, so every refusal carries a
 * reason and every reason has wording written for the person reading it —
 * the enum name never reaches the screen.
 *
 * The order of the cases is the order they are checked in, worst first: a
 * seller who is both suspended and below the minimum should be told about
 * the suspension.
 */
enum PayoutIneligibility: string
{
    case SellerNotEligible = 'seller_not_eligible';
    case PermissionRequired = 'permission_required';
    case PayoutAccountRequired = 'payout_account_required';
    case OpenPayoutExists = 'open_payout_exists';
    case NegativeBalance = 'negative_balance';
    case NoAvailableBalance = 'no_available_balance';
    case BelowMinimum = 'below_minimum';
    case CurrencyNotSupported = 'currency_not_supported';

    /**
     * What the seller is told, in their words rather than ours.
     *
     * @param  string|null  $detail  a formatted amount, where the reason needs one
     */
    public function message(?string $detail = null): string
    {
        return match ($this) {
            self::SellerNotEligible => 'Your store cannot request payouts at the moment. Contact support if you think this is wrong.',
            self::PermissionRequired => 'Only the store owner can request a payout.',
            self::PayoutAccountRequired => 'Add a payout destination before requesting a withdrawal.',
            self::OpenPayoutExists => 'You already have a payout in progress. It has to be decided before you can request another.',
            self::NegativeBalance => 'Your balance is below zero after recent refunds. New earnings will bring it back up before you can withdraw.',
            self::NoAvailableBalance => 'Nothing is available to withdraw yet. Earnings become available once orders are delivered and the clearing period ends.',
            self::BelowMinimum => $detail === null
                ? 'Your available balance is below the minimum payout.'
                : "The minimum payout is {$detail}.",
            self::CurrencyNotSupported => 'Payouts are not available in this currency yet.',
        };
    }
}
