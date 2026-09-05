<?php

declare(strict_types=1);

namespace App\Modules\Payouts\Support;

/**
 * The payout rules that are configuration rather than code.
 *
 * §11 and §12. A minimum payout and a supported-currency list are business
 * decisions, and business decisions that live as literals inside an action
 * get copied — one into the validator, one into the button's disabled
 * state, one into a test — and then diverge. They are read here, once.
 */
final class PayoutPolicy
{
    /** The smallest amount the platform will process. 0 means any positive amount. */
    public static function minimumMinor(): int
    {
        return max(0, (int) config('veritas.payouts.minimum_minor'));
    }

    /** The currency payouts operate in by default. */
    public static function currency(): string
    {
        return strtoupper((string) config('veritas.payouts.currency', 'USD'));
    }

    /** @return array<int, string> */
    public static function supportedCurrencies(): array
    {
        /** @var array<int, string> $configured */
        $configured = config('veritas.payouts.supported_currencies', ['USD']);

        return array_values(array_map(strtoupper(...), $configured));
    }

    public static function supports(string $currency): bool
    {
        return in_array(strtoupper($currency), self::supportedCurrencies(), true);
    }

    /**
     * Whether a seller must name a destination before withdrawing.
     *
     * On by default. Phase-1 settlement is a person making a transfer, and
     * "which account" being folklore rather than a record is how the wrong
     * one gets paid — but a pilot that genuinely settles by arrangement
     * can turn it off rather than invent a destination to satisfy a check.
     */
    public static function requiresDestination(): bool
    {
        return (bool) config('veritas.payouts.require_destination', true);
    }
}
