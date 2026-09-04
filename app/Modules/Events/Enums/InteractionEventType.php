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
    case CategoryViewed = 'category_viewed';
    case CartItemAdded = 'cart_item_added';
    case CartItemRemoved = 'cart_item_removed';
    case CartQuantityChanged = 'cart_quantity_changed';
    case CheckoutStarted = 'checkout_started';
    case CheckoutValidationFailed = 'checkout_validation_failed';
    case CheckoutOrderCreated = 'checkout_order_created';
    case PurchaseCompleted = 'purchase_completed';

    /**
     * A payment the provider refused.
     *
     * Recorded because a marketplace that cannot see its decline rate
     * cannot tell a provider problem from a pricing one — and because a
     * checkout that converts and then fails at the card is a different
     * failure from one that never got there.
     */
    case PaymentFailed = 'payment_failed';

    /**
     * Relative weight when building affinity scores. Kept with the event so
     * the offline job and any future model read one definition.
     */
    public function affinityWeight(): int
    {
        return match ($this) {
            self::ProductViewed, self::SellerStoreViewed, self::ProductVariantSelected,
            self::CategoryViewed => 1,
            self::SearchResultClicked => 2,
            self::CartItemAdded => 4,
            self::CartItemRemoved => -2,
            self::CartQuantityChanged => 2,
            self::CheckoutStarted => 5,
            // A checkout that could not proceed is not intent to abandon:
            // the customer wanted the thing enough to reach the last
            // page, and the marketplace failed them.
            self::CheckoutValidationFailed => 3,
            self::CheckoutOrderCreated => 8,
            self::PurchaseCompleted => 10,
            // Intent, and strong intent: they reached for a card. It is
            // simply not a purchase.
            self::PaymentFailed => 4,
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
            self::CategoryViewed => 'Category viewed',
            self::CartItemAdded => 'Added to cart',
            self::CartItemRemoved => 'Removed from cart',
            self::CartQuantityChanged => 'Cart quantity changed',
            self::CheckoutStarted => 'Checkout started',
            self::CheckoutValidationFailed => 'Checkout validation failed',
            self::CheckoutOrderCreated => 'Order created',
            self::PurchaseCompleted => 'Purchase completed',
            self::PaymentFailed => 'Payment failed',
        };
    }
}
