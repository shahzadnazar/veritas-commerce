<?php

declare(strict_types=1);

namespace App\Modules\Recommendations\Data;

use App\Modules\Inventory\Enums\StockState;
use App\Support\Money;

/**
 * One canonical product, as a recommendation shelf shows it.
 *
 * §30 lives in the type: a recommendation is a *product*, never an offer.
 * There is no seller on this record and no offer id, so a shelf physically
 * cannot render the same product three times because three sellers list
 * it — the duplicate would have to be two rows with the same productId,
 * and EligibleRecommendationProducts guarantees there is only ever one.
 *
 * Shaped like Catalog's ProductCard on purpose: a recommended product and
 * a searched one should look identical on screen, and the surest way to
 * get that is for the two payloads to carry the same keys.
 */
final readonly class RecommendedProduct
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
        public bool $inStock,
        public ?float $ratingAverage,
        public int $ratingCount,
        public string $reason,
    ) {}

    public function withReason(string $reason): self
    {
        return new self(
            productId: $this->productId,
            slug: $this->slug,
            title: $this->title,
            brandName: $this->brandName,
            imageUrl: $this->imageUrl,
            imageAlt: $this->imageAlt,
            displayPrice: $this->displayPrice,
            hasPriceRange: $this->hasPriceRange,
            offerCount: $this->offerCount,
            inStock: $this->inStock,
            ratingAverage: $this->ratingAverage,
            ratingCount: $this->ratingCount,
            reason: $reason,
        );
    }

    /**
     * The price a shelf shows.
     *
     * The same rule as a search card: the lowest eligible offer, a range
     * when sellers genuinely differ, and nothing at all when nobody lists
     * it — never a zero, which reads as free.
     */
    public static function formatPrice(?int $lowestMinor, ?int $highestMinor, ?string $currency): string
    {
        if ($lowestMinor === null) {
            return '';
        }

        $currency ??= (string) config('veritas.money.default_currency');
        $low = Money::of($lowestMinor, $currency)->format();

        if ($highestMinor === null || $highestMinor <= $lowestMinor) {
            return $low;
        }

        return $low.' – '.Money::of($highestMinor, $currency)->format();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $state = $this->inStock ? StockState::InStock : StockState::OutOfStock;

        return [
            'productId' => $this->productId,
            'slug' => $this->slug,
            'title' => $this->title,
            'brand' => $this->brandName,
            'imageUrl' => $this->imageUrl,
            'imageAlt' => $this->imageAlt,
            'price' => $this->displayPrice,
            'hasPriceRange' => $this->hasPriceRange,
            'offerCount' => $this->offerCount,
            'stockState' => $state->value,
            'stockLabel' => $state->label(),
            'ratingAverage' => $this->ratingAverage,
            'ratingCount' => $this->ratingCount,
            'reason' => $this->reason,
        ];
    }
}
