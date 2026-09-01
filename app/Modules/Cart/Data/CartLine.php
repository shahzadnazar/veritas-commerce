<?php

declare(strict_types=1);

namespace App\Modules\Cart\Data;

use App\Support\Money;

/**
 * One cart line, priced from the live offer.
 *
 * Everything financial here is a CURRENT estimate, recomputed on every
 * read. §9 draws the line: the cart shows what the customer would pay if
 * they checked out now, and the order — not this — is where a number stops
 * being able to change.
 */
final readonly class CartLine
{
    /** @param  array<int, CartIssue>  $issues */
    public function __construct(
        public string $lineIdentity,
        public int $offerId,
        public string $offerPublicId,
        public int $productId,
        public string $productTitle,
        public string $productSlug,
        public ?string $brandName,
        public ?string $variantName,
        public ?int $variantId,
        public int $sellerAccountId,
        public int $storeId,
        public string $storeName,
        public string $storeSlug,
        /* Carried so order creation does not look one up per line: the
         * commission rule may be scoped to a category. */
        public ?int $categoryId,
        public string $sellerSku,
        public int $quantity,
        public Money $unitPrice,
        public Money $lineTotal,
        public int $available,
        public bool $isBuyable,
        public array $issues = [],
        public ?string $imageUrl = null,
    ) {}

    public function hasBlockingIssue(): bool
    {
        foreach ($this->issues as $issue) {
            if ($issue->isBlocking()) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'lineIdentity' => $this->lineIdentity,
            'offerPublicId' => $this->offerPublicId,
            'productTitle' => $this->productTitle,
            'productSlug' => $this->productSlug,
            'brand' => $this->brandName,
            'variantName' => $this->variantName,
            'storeName' => $this->storeName,
            'storeSlug' => $this->storeSlug,
            'sellerSku' => $this->sellerSku,
            'quantity' => $this->quantity,
            'unitPrice' => $this->unitPrice->format(),
            'unitPriceMinor' => $this->unitPrice->minor,
            'lineTotal' => $this->lineTotal->format(),
            'lineTotalMinor' => $this->lineTotal->minor,
            'available' => $this->available,
            'isBuyable' => $this->isBuyable,
            'imageUrl' => $this->imageUrl,
            'issues' => array_map(static fn (CartIssue $issue): array => $issue->toArray(), $this->issues),
        ];
    }
}
