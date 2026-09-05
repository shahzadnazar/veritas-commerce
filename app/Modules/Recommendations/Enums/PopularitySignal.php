<?php

declare(strict_types=1);

namespace App\Modules\Recommendations\Enums;

use App\Modules\Events\Enums\InteractionEventType;

/**
 * The five behaviours popularity is built from.
 *
 * §36: the weights live in configuration, not scattered through the jobs
 * that use them, and the mapping from a raw event type to a signal lives
 * here rather than in a WHERE clause. A new interaction event added later
 * contributes nothing to popularity until somebody names it here, which is
 * the right default: an event whose meaning nobody has decided should not
 * quietly start moving products up a shelf.
 *
 * Deliberately smaller than InteractionEventType. That enum records what
 * happened; this one records what it is worth. Operational events —
 * shipment created, order delivered — have no signal at all, because a
 * seller pressing "sent" says nothing about what a shopper wants.
 */
enum PopularitySignal: string
{
    case View = 'view';
    case SearchClick = 'search_click';
    case Wishlist = 'wishlist';
    case Cart = 'cart';
    case Purchase = 'purchase';

    /**
     * The interaction events that count as this signal.
     *
     * Wishlist is absent on purpose: saving something is not an
     * interaction event, it is a row in wishlist_items, and the projection
     * counts it from there.
     *
     * @return array<int, InteractionEventType>
     */
    public function eventTypes(): array
    {
        return match ($this) {
            self::View => [InteractionEventType::ProductViewed],
            self::SearchClick => [InteractionEventType::SearchResultClicked],
            self::Cart => [InteractionEventType::CartItemAdded],
            self::Purchase => [InteractionEventType::PurchaseCompleted],
            self::Wishlist => [],
        };
    }

    /** @return array<int, string> */
    public function eventValues(): array
    {
        return array_map(
            static fn (InteractionEventType $type): string => $type->value,
            $this->eventTypes(),
        );
    }

    /**
     * What one occurrence of this signal is worth.
     *
     * Read from configuration every time rather than captured in a
     * constant, so an operator changing a weight and rebuilding gets the
     * weight they configured. Clamped at zero: a negative weight would let
     * a popular product score below an unseen one, which is not a ranking
     * anybody can explain.
     */
    public function weight(): int
    {
        $configured = config('veritas.recommendations.weights.'.$this->value);

        return max(0, is_numeric($configured) ? (int) $configured : 0);
    }

    /** The column on product_popularity_scores this signal counts into. */
    public function countColumn(): string
    {
        return $this->value.'_count';
    }

    public function label(): string
    {
        return match ($this) {
            self::View => 'Views',
            self::SearchClick => 'Search clicks',
            self::Wishlist => 'Wishlist saves',
            self::Cart => 'Added to cart',
            self::Purchase => 'Purchases',
        };
    }
}
