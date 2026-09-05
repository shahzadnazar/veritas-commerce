<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Support;

/**
 * Review text is untrusted input, treated as such once, here. §13.
 *
 * The policy is PLAIN TEXT. Not "sanitised HTML" — a marketplace does not
 * need a customer to be able to bold a word, and every allowlist of tags
 * eventually meets an attribute nobody thought about. Markup is stripped
 * rather than escaped, so what is stored is what a person would read
 * aloud, and there is no context in which it becomes live again.
 *
 * That last part is the reason this is a normalisation and not an
 * escape-on-render. React escapes what it renders, but review text also
 * reaches a JSON-LD block, an admin export, an email, and whatever surface
 * comes next — and a value that is only safe because of where it happens
 * to be displayed is a value waiting for a new display.
 */
final class ReviewText
{
    public const MAX_TITLE = 120;

    public const MAX_BODY = 4000;

    public const MIN_BODY = 10;

    /**
     * Reduce a submission to plain text.
     *
     * Tags go, entities are decoded so `&lt;script&gt;` cannot survive as
     * a second layer, control characters go, and runs of whitespace
     * collapse to something a person typed rather than something a script
     * padded.
     */
    public static function clean(string $value): string
    {
        // Decode first, then strip: the other order leaves an encoded tag
        // intact and turns it back into markup afterwards.
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = strip_tags($value);

        // Control characters, including the zero-width and direction
        // marks used to disguise text, but keeping newlines and tabs.
        $value = (string) preg_replace('/[^\P{C}\n\t]+/u', '', $value);

        // At most one blank line, and no trailing spaces on any of them.
        $value = (string) preg_replace('/[ \t]+/', ' ', $value);
        $value = (string) preg_replace('/\n{3,}/', "\n\n", $value);

        return trim($value);
    }

    public static function title(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = mb_substr(self::clean($value), 0, self::MAX_TITLE);

        return $clean === '' ? null : $clean;
    }

    public static function body(string $value): string
    {
        return mb_substr(self::clean($value), 0, self::MAX_BODY);
    }

    /**
     * Whether what is left is a review rather than an artefact.
     *
     * Checked after cleaning, deliberately: a body of nothing but a script
     * tag cleans to an empty string, and it should be refused as too short
     * rather than stored as a blank review.
     */
    public static function isUsableBody(string $cleaned): bool
    {
        return mb_strlen($cleaned) >= self::MIN_BODY;
    }
}
