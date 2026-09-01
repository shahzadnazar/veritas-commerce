<?php

declare(strict_types=1);

namespace App\Modules\Search\Enums;

/**
 * How a results page may be ordered.
 *
 * An enum rather than a string, because sort arrives in a URL and a sort
 * key that reaches SQL unvalidated is an injection waiting to happen.
 * Anything unrecognised falls back to relevance rather than erroring: a
 * stale bookmark should still show results.
 *
 * There is deliberately no "Best selling" or "Top rated". No orders and no
 * reviews exist yet, so either would be a label over invented data.
 */
enum SortOption: string
{
    case Relevance = 'relevance';
    case PriceAscending = 'price_asc';
    case PriceDescending = 'price_desc';
    case Newest = 'newest';

    public static function fromRequest(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Relevance;
    }

    /**
     * Relevance is only meaningful with a query behind it.
     *
     * Sorting an unfiltered category page by "relevance to nothing" would
     * be an arbitrary order presented as a considered one, so browsing
     * falls back to newest.
     */
    public function resolvedFor(bool $hasQuery): self
    {
        return $this === self::Relevance && ! $hasQuery ? self::Newest : $this;
    }

    public function label(): string
    {
        return match ($this) {
            self::Relevance => 'Most relevant',
            self::PriceAscending => 'Price: low to high',
            self::PriceDescending => 'Price: high to low',
            self::Newest => 'Newest',
        };
    }
}
