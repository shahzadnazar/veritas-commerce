<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Modules\Payments\Data\ProviderFailure;

/**
 * What a payment failure says to the person who has to act on it.
 *
 * §53. A provider's own message is written for a developer reading an API
 * response, and showing it to a shopper does two things wrong at once: it
 * is unhelpful ("card_declined: Your card was declined." tells them nothing
 * they can do), and a decline code is a signal a card tester uses to tune
 * the next attempt.
 *
 * So there are two vocabularies. The provider's code and message are kept
 * on the attempt for operators. This is what a customer reads.
 */
final class PaymentLanguage
{
    public static function forCustomer(?ProviderFailure $failure): string
    {
        return match ($failure?->code) {
            'card_declined', 'do_not_honor' => 'Your payment could not be completed. '
                .'Please try another payment method.',
            'insufficient_funds' => 'Your payment could not be completed. '
                .'Please try another payment method.',
            'expired_card' => 'That card has expired. Please try another payment method.',
            'incorrect_cvc', 'invalid_cvc' => 'Those card details could not be verified. '
                .'Please check them and try again.',
            'processing_error' => 'Something went wrong while taking your payment. Please try again.',
            'authentication_required' => 'Your bank needs to confirm this payment. Please try again.',
            // The default is deliberately the same sentence as most of the
            // specific cases: the customer's options are identical, and a
            // more precise message would mostly be describing the inside of
            // the machine.
            default => 'Your payment could not be completed. Please try another payment method.',
        };
    }

    /**
     * Whether the customer can reasonably fix this by trying again.
     *
     * No failure at all means yes: an attempt that ended without a
     * provider reason is one the customer abandoned, not one the bank
     * refused.
     */
    public static function isRetryable(?ProviderFailure $failure): bool
    {
        return $failure === null || $failure->retryable;
    }
}
