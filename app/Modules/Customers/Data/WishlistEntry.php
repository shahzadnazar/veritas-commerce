<?php

declare(strict_types=1);

namespace App\Modules\Customers\Data;

use App\Modules\Inventory\Enums\StockState;
use App\Support\Money;

/**
 * One saved product, as the wishlist page shows it.
 *
 * Carries `isAvailable` so a saved product that has since been withdrawn
 * can be shown as unavailable rather than silently dropped. Dropping it
 * is the tempting option and the wrong one: a customer who saved six
 * things and sees five will assume the site lost one, and a list that
 * quietly shrinks is worse than one that says "no longer sold".
 */
final readonly class WishlistEntry
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
        public bool $isAvailable,
        public ?float $ratingAverage,
        public int $ratingCount,
        public string $savedAt,
    ) {}

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
            'isAvailable' => $this->isAvailable,
            'ratingAverage' => $this->ratingAverage,
            'ratingCount' => $this->ratingCount,
            'savedAt' => $this->savedAt,
        ];
    }
}
