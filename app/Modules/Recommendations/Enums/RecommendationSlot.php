<?php

declare(strict_types=1);

namespace App\Modules\Recommendations\Enums;

/**
 * Where a set of recommendations appears, and therefore what it means.
 *
 * §29: a page asks for a slot, never for a strategy. The slot decides
 * which strategies run and in what order, so the shelf on the product page
 * can change from "bought together" to "similar" without a controller
 * knowing that happened — and so two pages showing the same shelf cannot
 * end up running different logic.
 */
enum RecommendationSlot: string
{
    /** On a product page: the honest comparison set. */
    case SimilarProducts = 'similar_products';

    /** On a product page: what other people put in the same basket. */
    case BoughtTogether = 'bought_together';

    /** On a product page: what other people looked at in the same visit. */
    case AlsoViewed = 'also_viewed';

    /** On the home page, for a returning visitor. */
    case RecentlyViewed = 'recently_viewed';

    /** On the home page, for everyone. */
    case Trending = 'trending';

    /** On the home page and category pages: the personal shelf. */
    case ForYou = 'for_you';

    /** On a cart page: what completes this basket. */
    case CartAddons = 'cart_addons';

    /** On an empty search or a dead end. */
    case NewArrivals = 'new_arrivals';

    /**
     * A customer-facing heading. Kept with the slot because a shelf whose
     * title is written in React and whose contents are chosen in PHP is a
     * shelf that will eventually say one thing and show another.
     */
    public function title(): string
    {
        return match ($this) {
            self::SimilarProducts => 'Similar products',
            self::BoughtTogether => 'Frequently bought together',
            self::AlsoViewed => 'Customers also viewed',
            self::RecentlyViewed => 'Recently viewed',
            self::Trending => 'Trending now',
            self::ForYou => 'Recommended for you',
            self::CartAddons => 'Complete your order',
            self::NewArrivals => 'New arrivals',
        };
    }

    /** Whether the slot is meaningless without a product to anchor on. */
    public function requiresAnchor(): bool
    {
        return match ($this) {
            self::SimilarProducts, self::BoughtTogether, self::AlsoViewed => true,
            self::RecentlyViewed, self::Trending, self::ForYou,
            self::CartAddons, self::NewArrivals => false,
        };
    }

    /**
     * Whether the slot is personal to the visitor.
     *
     * A personal shelf must never be cached across visitors, and this is
     * what RecommendationService reads to decide that — rather than each
     * caller remembering to pass a flag.
     */
    public function isPersonal(): bool
    {
        return match ($this) {
            self::RecentlyViewed, self::ForYou, self::CartAddons => true,
            self::SimilarProducts, self::BoughtTogether, self::AlsoViewed,
            self::Trending, self::NewArrivals => false,
        };
    }

    public function defaultLimit(): int
    {
        return match ($this) {
            self::BoughtTogether, self::CartAddons => 6,
            default => 12,
        };
    }
}
