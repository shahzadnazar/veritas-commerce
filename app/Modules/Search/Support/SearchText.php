<?php

declare(strict_types=1);

namespace App\Modules\Search\Support;

use Illuminate\Support\Str;

/**
 * One normalisation, used by the indexer and the query alike.
 *
 * If these two ever disagree the index becomes unsearchable in a way no
 * test would notice: documents stored under one spelling, queries asking
 * for another. So there is one function, and both sides call it.
 */
final class SearchText
{
    public static function normalise(string $value): string
    {
        return Str::of($value)
            ->ascii()
            ->lower()
            // Punctuation separates rather than vanishes, so "1.2L" stays
            // two tokens rather than collapsing to "12l".
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->value();
    }

    /** An identifier as it is compared: digits only, case-folded. */
    public static function normaliseIdentifier(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = preg_replace('/[^A-Za-z0-9]/', '', $value) ?? '';

        return $clean === '' ? null : strtolower($clean);
    }
}
