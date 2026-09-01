<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Support;

use Illuminate\Support\Str;

/**
 * Normalisation used for matching, not for display.
 *
 * Deterministic and boring on purpose: two sellers typing "Apple iPhone 17
 * Pro" and "apple  iphone 17 pro." should collide, and nothing here is
 * clever enough to make a match nobody can explain afterwards.
 */
final class CatalogueText
{
    /**
     * The comparison form of a title or a brand name.
     *
     * Lowercased, accents folded, punctuation dropped, whitespace
     * collapsed. What survives is the sequence of words.
     */
    public static function normalise(string $value): string
    {
        return Str::of($value)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->value();
    }

    /**
     * A trade identifier, stripped to its digits.
     *
     * GTINs are written with spaces and hyphens in catalogues and without
     * them in databases; "0-71234-56789-4" and "0712345678 94" are the
     * same barcode.
     */
    public static function normaliseIdentifier(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = preg_replace('/[^0-9Xx]/', '', $value);
        $clean = strtoupper((string) $clean);

        return $clean === '' ? null : $clean;
    }

    /**
     * Whether a GTIN-family identifier's check digit agrees with the rest.
     *
     * A mistyped barcode that passes into the catalogue creates a
     * duplicate nobody can find; the check digit exists precisely to catch
     * that, and it costs one loop.
     */
    public static function hasValidGtinCheckDigit(string $identifier): bool
    {
        if (! preg_match('/^\d{8}$|^\d{12}$|^\d{13}$|^\d{14}$/', $identifier)) {
            return false;
        }

        $digits = array_map('intval', str_split($identifier));
        $check = (int) array_pop($digits);

        // Weights alternate 3 and 1 from the right, whatever the length.
        $sum = 0;
        foreach (array_reverse($digits) as $index => $digit) {
            $sum += $digit * ($index % 2 === 0 ? 3 : 1);
        }

        return (10 - ($sum % 10)) % 10 === $check;
    }
}
