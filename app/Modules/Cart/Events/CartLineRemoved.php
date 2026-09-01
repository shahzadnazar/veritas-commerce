<?php

declare(strict_types=1);

namespace App\Modules\Cart\Events;

/**
 * A customer took something out of their cart, or stepped its quantity
 * down to nothing.
 *
 * The negative half of the intent signal, and the more informative half:
 * what a shopper removes says more about a bad price or a bad delivery
 * estimate than what they add.
 */
final readonly class CartLineRemoved
{
    public function __construct(
        public int $cartId,
        public string $lineIdentity,
        public int $offerId,
        public ?int $productId,
        public ?int $sellerAccountId,
        public int $quantity,
        public int $unitPriceMinor,
    ) {}

    public function valueMinor(): int
    {
        return $this->unitPriceMinor * $this->quantity;
    }
}
