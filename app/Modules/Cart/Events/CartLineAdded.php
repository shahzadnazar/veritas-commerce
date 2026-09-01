<?php

declare(strict_types=1);

namespace App\Modules\Cart\Events;

/**
 * A customer put a seller's offer in their cart.
 *
 * Scalars, not models: by the time anything reads this the price may have
 * moved, and the event should say what was true when it happened.
 */
final readonly class CartLineAdded
{
    public function __construct(
        public int $cartId,
        public string $lineIdentity,
        public int $offerId,
        public int $productId,
        public int $sellerAccountId,
        public int $quantity,
        public int $unitPriceMinor,
        /** Total quantity on the line afterwards, which a re-add increases. */
        public int $lineQuantity,
    ) {}

    public function valueMinor(): int
    {
        return $this->unitPriceMinor * $this->quantity;
    }
}
