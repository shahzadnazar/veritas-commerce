<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Human-facing references.
 *
 * Marketplace order:  VC-24081
 * Seller sub-order:   VC-24081-01, VC-24081-02, VC-24081-03
 *
 * The sub-order suffix is a zero-padded two-digit sequence within its parent,
 * never an alphabetic suffix. Decision 1, settled: the customer sees one
 * number; the seller sees their slice of it.
 */
final class Reference
{
    public const ORDER_PREFIX = 'VC';

    public static function order(int $sequence): string
    {
        if ($sequence < 1) {
            throw new InvalidArgumentException('Order sequence must be positive.');
        }

        return self::ORDER_PREFIX.'-'.$sequence;
    }

    public static function subOrder(string $orderReference, int $position): string
    {
        if ($position < 1 || $position > 99) {
            throw new InvalidArgumentException('Sub-order position must be between 1 and 99.');
        }

        return $orderReference.'-'.str_pad((string) $position, 2, '0', STR_PAD_LEFT);
    }

    public static function application(int $sequence): string
    {
        return 'APP-'.$sequence;
    }

    public static function payout(int $sequence): string
    {
        return 'PO-'.$sequence;
    }

    public static function refund(int $sequence): string
    {
        return 'RF-'.$sequence;
    }

    /** Extract the parent order reference from a sub-order reference. */
    public static function parentOf(string $subOrderReference): string
    {
        $parts = explode('-', $subOrderReference);

        if (count($parts) !== 3) {
            throw new InvalidArgumentException("Not a sub-order reference: {$subOrderReference}");
        }

        return $parts[0].'-'.$parts[1];
    }
}
