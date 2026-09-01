<?php

declare(strict_types=1);

namespace App\Modules\Cart\Events;

/**
 * A customer changed how many of something they want.
 *
 * Distinct from an add: a shopper stepping from two to three has not made
 * a fresh decision to buy, and counting it as one would inflate every
 * intent signal a re-add produces. Distinct from a removal too — going to
 * zero announces CartLineRemoved instead.
 */
final readonly class CartLineQuantityChanged
{
    public function __construct(
        public int $cartId,
        public string $lineIdentity,
        public int $offerId,
        public ?int $productId,
        public ?int $sellerAccountId,
        public int $from,
        public int $to,
        public int $unitPriceMinor,
    ) {}

    public function valueMinor(): int
    {
        return $this->unitPriceMinor * $this->to;
    }
}
