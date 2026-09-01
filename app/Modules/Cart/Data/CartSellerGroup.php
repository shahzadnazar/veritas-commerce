<?php

declare(strict_types=1);

namespace App\Modules\Cart\Data;

use App\Support\Money;

/**
 * The lines a customer is buying from one seller.
 *
 * This grouping becomes a seller order at checkout, which is why it exists
 * in the cart already: the customer should be able to see the shape of
 * what they are about to buy before they buy it.
 */
final readonly class CartSellerGroup
{
    /** @param  array<int, CartLine>  $lines */
    public function __construct(
        public int $sellerAccountId,
        public string $storeName,
        public string $storeSlug,
        public array $lines,
        public Money $subtotal,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'sellerAccountId' => $this->sellerAccountId,
            'storeName' => $this->storeName,
            'storeSlug' => $this->storeSlug,
            'lines' => array_map(static fn (CartLine $line): array => $line->toArray(), $this->lines),
            'subtotal' => $this->subtotal->format(),
            'subtotalMinor' => $this->subtotal->minor,
        ];
    }
}
