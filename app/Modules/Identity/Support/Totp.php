<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use InvalidArgumentException;

/**
 * RFC 6238 time-based one-time passwords, over RFC 4226 HOTP.
 *
 * This is not custom cryptography: the construction is HMAC-SHA1 from the
 * PHP standard library, applied exactly as the RFCs specify, and the code
 * is interoperable with any authenticator app. What is implemented here is
 * the base32 alphabet and the dynamic-truncation step — both are pure
 * encoding, not primitives.
 *
 * Verification compares in constant time and accepts a small window either
 * side of the current step, because a phone's clock drifts.
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public const PERIOD_SECONDS = 30;

    public const DIGITS = 6;

    /** How many 30-second steps either side of now are accepted. */
    public const DRIFT_STEPS = 1;

    /** A fresh 160-bit secret, base32 encoded as authenticator apps expect. */
    public static function generateSecret(int $bytes = 20): string
    {
        // RFC 4226 requires at least 128 bits and recommends 160. A caller
        // asking for less is a bug worth stopping, not rounding up.
        if ($bytes < 16) {
            throw new InvalidArgumentException('A TOTP secret must be at least 128 bits (16 bytes).');
        }

        return self::base32Encode(random_bytes($bytes));
    }

    public static function codeAt(string $secret, int $timestamp): string
    {
        $counter = intdiv($timestamp, self::PERIOD_SECONDS);
        $binary = pack('J', $counter);

        $hash = hash_hmac('sha1', $binary, self::base32Decode($secret), true);

        // Dynamic truncation, RFC 4226 §5.4.
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;

        $truncated = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad(
            (string) ($truncated % (10 ** self::DIGITS)),
            self::DIGITS,
            '0',
            STR_PAD_LEFT,
        );
    }

    /**
     * Constant-time verification across the drift window.
     *
     * Every candidate is compared even after a match, so the time taken
     * does not reveal which step succeeded.
     */
    public static function verify(string $secret, string $code, ?int $now = null): bool
    {
        $code = trim(str_replace(' ', '', $code));

        if (! preg_match('/^\d{'.self::DIGITS.'}$/', $code)) {
            return false;
        }

        $now ??= time();
        $matched = false;

        for ($step = -self::DRIFT_STEPS; $step <= self::DRIFT_STEPS; $step++) {
            $candidate = self::codeAt($secret, $now + ($step * self::PERIOD_SECONDS));

            if (hash_equals($candidate, $code)) {
                $matched = true;
            }
        }

        return $matched;
    }

    /**
     * The otpauth:// URI an authenticator app scans.
     *
     * The secret appears here because enrolment cannot happen without it —
     * this URI is rendered once, during setup, and never stored or logged.
     */
    public static function provisioningUri(string $secret, string $account, string $issuer): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            rawurlencode($issuer),
            rawurlencode($account),
            $secret,
            rawurlencode($issuer),
            self::DIGITS,
            self::PERIOD_SECONDS,
        );
    }

    public static function base32Encode(string $binary): string
    {
        $bits = '';

        foreach (str_split($binary) as $character) {
            $bits .= str_pad(decbin(ord($character)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';

        foreach (str_split($bits, 5) as $chunk) {
            // Five bits index the 32-character alphabet exactly. bindec()
            // widens to float past PHP_INT_MAX, which five bits cannot
            // reach; the modulo states the bound the alphabet relies on.
            $index = (int) bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT)) % 32;

            $encoded .= self::ALPHABET[abs($index)];
        }

        return $encoded;
    }

    public static function base32Decode(string $encoded): string
    {
        $encoded = rtrim(strtoupper($encoded), '=');
        $bits = '';

        foreach (str_split($encoded) as $character) {
            $index = strpos(self::ALPHABET, $character);

            if ($index === false) {
                throw new InvalidArgumentException('The secret is not valid base32.');
            }

            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $binary = '';

        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                // Eight bits is a byte: 0-255, never wide enough for
                // bindec() to return a float.
                $binary .= chr((int) bindec($chunk) % 256);
            }
        }

        return $binary;
    }
}
