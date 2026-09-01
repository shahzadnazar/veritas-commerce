<?php

declare(strict_types=1);

namespace App\Modules\Cart\Support;

/**
 * What makes two cart lines the same line.
 *
 * The extensibility seam §5 asks for. Today the answer is "the same offer
 * and the same variant", so adding a kettle twice combines into one line
 * of two — which is what a customer double-clicking Add expects.
 *
 * The moment a product carries something that distinguishes one
 * customer's copy from another's — an engraving, a gift message, a cut
 * length — those go into `$customisation` and two lines stay two lines.
 * Nothing else in the system has to learn about it: the uniqueness
 * constraint is on this value, not on the offer id.
 *
 * Deterministic and order-independent, so the same choices always produce
 * the same identity however the array was assembled.
 */
final class LineIdentity
{
    /** @param  array<string, scalar|null>  $customisation */
    public static function for(int $offerId, ?int $variantId = null, array $customisation = []): string
    {
        $parts = ['offer' => $offerId, 'variant' => $variantId ?? 0];

        foreach ($customisation as $key => $value) {
            $parts['c:'.$key] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        }

        // Sorted, so ['size' => 'L', 'colour' => 'red'] and the reverse
        // are one line rather than two.
        ksort($parts);

        $canonical = [];

        foreach ($parts as $key => $value) {
            $canonical[] = $key.'='.$value;
        }

        // Hashed to a fixed width: a customisation could otherwise be long
        // enough to overflow the column, and a truncated identity would
        // silently merge two different things.
        return $customisation === []
            ? 'offer:'.$offerId.($variantId === null ? '' : ':v'.$variantId)
            : 'h:'.hash('sha256', implode('|', $canonical));
    }
}
