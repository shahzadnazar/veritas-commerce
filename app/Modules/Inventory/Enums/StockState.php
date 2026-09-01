<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Enums;

use App\Support\HasStatusTone;
use App\Support\StatusTone;

/**
 * How much of something is left, as one word.
 *
 * There is exactly one place this is decided, because three places would
 * disagree. The seller portal, the storefront product page, the category
 * listing and the search index all read this enum through StockLevel — none
 * of them re-derives "is it low" from a number and a threshold, which is
 * how a product ends up labelled In stock on one page and Low stock on the
 * next.
 */
enum StockState: string implements HasStatusTone
{
    case InStock = 'in_stock';
    case LowStock = 'low_stock';
    case OutOfStock = 'out_of_stock';

    /**
     * The state a given availability implies.
     *
     * A threshold of zero means the seller does not want a low-stock
     * warning at all, which is a real choice and different from not having
     * set one.
     */
    public static function forAvailability(int $available, int $threshold): self
    {
        if ($available <= 0) {
            return self::OutOfStock;
        }

        return $threshold > 0 && $available <= $threshold ? self::LowStock : self::InStock;
    }

    /**
     * How bad this is, for deciding whether a change is worth an email.
     *
     * A seller told "you have sold out" does not need "you are running
     * low" when a cancelled hold puts two units back — that is an
     * improvement, and improvements are not news.
     */
    public function severity(): int
    {
        return match ($this) {
            self::InStock => 0,
            self::LowStock => 1,
            self::OutOfStock => 2,
        };
    }

    public function isWorseThan(self $other): bool
    {
        return $this->severity() > $other->severity();
    }

    public function isBuyable(): bool
    {
        return $this !== self::OutOfStock;
    }

    /** Whether reaching this state is worth telling the seller about. */
    public function warrantsNotification(): bool
    {
        return $this !== self::InStock;
    }

    /** schema.org, for the product page's structured data. */
    public function schemaAvailability(): string
    {
        return match ($this) {
            self::InStock, self::LowStock => 'https://schema.org/InStock',
            self::OutOfStock => 'https://schema.org/OutOfStock',
        };
    }

    public function tone(): StatusTone
    {
        return match ($this) {
            self::InStock => StatusTone::Neutral,
            self::LowStock => StatusTone::Pending,
            self::OutOfStock => StatusTone::Critical,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::InStock => 'In stock',
            self::LowStock => 'Low stock',
            self::OutOfStock => 'Out of stock',
        };
    }
}
