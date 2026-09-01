<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Exceptions;

use App\Modules\Cart\Data\CartIssue;
use RuntimeException;

/**
 * The checkout could not proceed, and why.
 *
 * Carries the structured issues rather than a sentence, so the cart page
 * can point at the offending line, the attempt row can record the reason,
 * and a test can assert on it — three things a formatted message cannot
 * support.
 */
final class CheckoutRefused extends RuntimeException
{
    /**
     * @param  array<int, CartIssue>  $issues
     */
    public function __construct(
        string $message,
        public readonly array $issues = [],
        public readonly string $reason = 'not_buyable',
    ) {
        parent::__construct($message);
    }

    /** @param array<int, CartIssue> $issues */
    public static function cartIsNotBuyable(array $issues): self
    {
        return new self(
            'Some items in your basket need attention before you can check out.',
            $issues,
            'cart_not_buyable',
        );
    }

    public static function cartIsEmpty(): self
    {
        return new self('There is nothing in your basket.', reason: 'cart_empty');
    }

    public static function stockRanOut(): self
    {
        return new self(
            'Someone bought the last of something in your basket while you were checking out.',
            reason: 'stock_unavailable',
        );
    }

    /**
     * The same idempotency key, presented for a different cart or by a
     * different customer.
     *
     * Refused rather than resolved: returning the first attempt would hand
     * one customer another's order, and starting a second attempt under
     * the same key would defeat the guarantee the key exists for.
     */
    public static function keyBelongsToAnotherCheckout(): self
    {
        return new self('That checkout reference is already in use.', reason: 'idempotency_conflict');
    }
}
