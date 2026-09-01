<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Data;

use App\Modules\Inventory\Enums\StockState;
use App\Modules\Media\Contracts\ObjectStore;
use App\Modules\Media\Enums\Visibility;
use App\Modules\Search\Data\SearchHit;
use App\Support\Money;

/**
 * One product, as every listing surface shows it.
 *
 * §29 in a class: search, category pages and store pages render the same
 * card from the same shape, so a price cannot be formatted one way here
 * and another way there, and availability cannot say "In stock" on one
 * page and nothing on the next.
 *
 * Built from a SearchHit, which already carries everything — the point of
 * denormalising the search document was that a page of results costs one
 * query, and re-hydrating models to build cards would put the joins back.
 */
final readonly class ProductCard
{
    public function __construct(
        public int $productId,
        public string $slug,
        public string $title,
        public ?string $brandName,
        public ?string $imageUrl,
        public ?string $imageAlt,
        public string $displayPrice,
        public bool $hasPriceRange,
        public int $offerCount,
        public string $stockState,
        public string $stockLabel,
    ) {}

    public static function fromHit(SearchHit $hit, ObjectStore $objects): self
    {
        // Availability is the index's, which is the inventory ledger's:
        // nothing here recomputes what is in stock.
        $state = $hit->inStock ? StockState::InStock : StockState::OutOfStock;

        return new self(
            productId: $hit->productId,
            slug: $hit->slug,
            title: $hit->title,
            brandName: $hit->brandName,
            imageUrl: self::imageUrl($hit, $objects),
            imageAlt: $hit->imageAlt,
            displayPrice: self::price($hit),
            hasPriceRange: $hit->hasPriceRange(),
            offerCount: $hit->offerCount,
            stockState: $state->value,
            stockLabel: $state->label(),
        );
    }

    /**
     * The price a customer sees.
     *
     * The lowest eligible offer, which is the same offer the ranking
     * service would feature on the product page — so a card and the page
     * it leads to cannot quote different numbers. A range is shown when
     * sellers genuinely differ; a product nobody lists shows no price at
     * all rather than a zero.
     */
    private static function price(SearchHit $hit): string
    {
        if ($hit->lowestPriceMinor === null) {
            return '';
        }

        $currency = $hit->currency ?? (string) config('veritas.money.default_currency');
        $low = Money::of($hit->lowestPriceMinor, $currency)->format();

        if (! $hit->hasPriceRange()) {
            return $low;
        }

        return $low.' – '.Money::of((int) $hit->highestPriceMinor, $currency)->format();
    }

    private static function imageUrl(SearchHit $hit, ObjectStore $objects): ?string
    {
        if ($hit->imagePath === null) {
            return null;
        }

        return $objects->url($objects->fromReference(
            ($hit->imageDisk ?? '').':'.$hit->imagePath,
            Visibility::Public,
        ));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'title' => $this->title,
            'brand' => $this->brandName,
            'imageUrl' => $this->imageUrl,
            'imageAlt' => $this->imageAlt,
            'price' => $this->displayPrice,
            'hasPriceRange' => $this->hasPriceRange,
            'offerCount' => $this->offerCount,
            'stockState' => $this->stockState,
            'stockLabel' => $this->stockLabel,
        ];
    }
}
