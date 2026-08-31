<?php

declare(strict_types=1);

namespace App\Modules\Events\Enums;

/**
 * Behavioural events captured from the first release.
 *
 * No recommendation model ships in M0. This exists now because a recommender
 * built in month six on no history recommends noise — and because
 * result_position, recorded at click time, is the only thing that makes
 * search-ranking training data usable later.
 */
enum InteractionEventType: string
{
    case SearchPerformed = 'search_performed';
    case SearchResultClicked = 'search_result_clicked';
    case ProductViewed = 'product_viewed';
    case ProductVariantSelected = 'product_variant_selected';
    case SellerStoreViewed = 'seller_store_viewed';
    case CartItemAdded = 'cart_item_added';
    case CartItemRemoved = 'cart_item_removed';
    case CheckoutStarted = 'checkout_started';
    case PurchaseCompleted = 'purchase_completed';

    /**
     * Relative weight when building affinity scores. Kept with the event so
     * the offline job and any future model read one definition.
     */
    public function affinityWeight(): int
    {
        return match ($this) {
            self::ProductViewed, self::SellerStoreViewed, self::ProductVariantSelected => 1,
            self::SearchResultClicked => 2,
            self::CartItemAdded => 4,
            self::CartItemRemoved => -2,
            self::CheckoutStarted => 5,
            self::PurchaseCompleted => 10,
            self::SearchPerformed => 0,
        };
    }

    public function requiresSearchQuery(): bool
    {
        return in_array($this, [self::SearchPerformed, self::SearchResultClicked], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::SearchPerformed => 'Search performed',
            self::SearchResultClicked => 'Search result clicked',
            self::ProductViewed => 'Product viewed',
            self::ProductVariantSelected => 'Variant selected',
            self::SellerStoreViewed => 'Store viewed',
            self::CartItemAdded => 'Added to cart',
            self::CartItemRemoved => 'Removed from cart',
            self::CheckoutStarted => 'Checkout started',
            self::PurchaseCompleted => 'Purchase completed',
        };
    }
}
