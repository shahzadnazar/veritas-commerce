<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Support;

/**
 * One phrase, one row.
 *
 * "Kettle", "kettle " and "KETTLE  cordless" being three separate lines in
 * a search report is the difference between a list somebody acts on and a
 * list somebody scrolls past. Normalisation happens once, here, so the
 * projection and any future reader agree on what counts as the same
 * question.
 *
 * Deliberately conservative: case, surrounding space and repeated inner
 * space only. No stemming, no synonym folding, no spelling correction —
 * those are search-engine concerns, and applying them to the report would
 * hide exactly the misspellings a catalogue team needs to see.
 */
final class SearchPhrase
{
    /** Matches the query_normalised column. */
    public const MAX_LENGTH = 200;

    public static function normalise(string $phrase): ?string
    {
        $collapsed = preg_replace('/\s+/u', ' ', $phrase);
        $trimmed = trim($collapsed ?? $phrase);

        if ($trimmed === '') {
            return null;
        }

        $lowered = mb_strtolower($trimmed);

        return mb_strlen($lowered) > self::MAX_LENGTH
            ? mb_substr($lowered, 0, self::MAX_LENGTH)
            : $lowered;
    }
}
