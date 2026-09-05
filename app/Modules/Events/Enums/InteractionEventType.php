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

    /**
     * A product appearing in a page of results.
     *
     * The denominator a click-through rate needs. Without it, "this
     * product got four clicks" cannot be compared with "this one got
     * forty", because the second may simply have been shown ten times as
     * often — and a catalogue team acting on click counts alone will
     * promote whatever already ranks first.
     */
    case SearchResultShown = 'search_result_shown';
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
     * A product saved to a wishlist, and one removed from it.
     *
     * The wishlist row is the truth; these record *when* somebody changed
     * their mind, which a table of current saves cannot answer.
     */
    case WishlistItemAdded = 'wishlist_item_added';
    case WishlistItemRemoved = 'wishlist_item_removed';

    /**
     * A recommendation shelf rendered, and one of its cards clicked.
     *
     * Recorded so a shelf can be judged. Without the impression, a click
     * count says only that some shelves get clicked; with it, the two
     * together say which shelves are worth their space and which are
     * furniture — which is the only honest way to decide whether the
     * fallback chain is doing more harm than good.
     */
    case RecommendationShown = 'recommendation_shown';
    case RecommendationClicked = 'recommendation_clicked';

    /**
     * A payment the provider refused.
     *
     * Recorded because a marketplace that cannot see its decline rate
     * cannot tell a provider problem from a pricing one — and because a
     * checkout that converts and then fails at the card is a different
     * failure from one that never got there.
     */
    case PaymentFailed = 'payment_failed';

    /*
     * Operational events, recorded so the marketplace can see how long
     * fulfilment actually takes.
     *
     * They carry no affinity weight: a seller pressing "sent" says nothing
     * about what a shopper wants, and letting operational volume influence
     * ranking would make the busiest warehouse the most recommended shop.
     */
    case OrderConfirmed = 'order_confirmed';
    case ShipmentCreated = 'shipment_created';
    case ShipmentShipped = 'shipment_shipped';
    case ShipmentDelivered = 'shipment_delivered';
    case OrderDelivered = 'order_delivered';

    /**
     * Relative weight when building affinity scores. Kept with the event so
     * the offline job and any future model read one definition.
     */
    public function affinityWeight(): int
    {
        return match ($this) {
            self::ProductViewed, self::SellerStoreViewed, self::ProductVariantSelected,
            self::CategoryViewed => 1,
            self::SearchResultClicked, self::RecommendationClicked => 2,
            // Saving something is a durable statement of intent —
            // stronger than a look, weaker than a basket.
            self::WishlistItemAdded => 3,
            self::WishlistItemRemoved => -1,
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
            // Something being *shown* says nothing about the visitor. It
            // is the denominator for the click, not a signal of its own.
            self::SearchResultShown, self::RecommendationShown => 0,
            // Operational, not behavioural. See the cases above.
            self::OrderConfirmed, self::ShipmentCreated, self::ShipmentShipped,
            self::ShipmentDelivered, self::OrderDelivered => 0,
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
            self::SearchResultShown => 'Search result shown',
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
            self::WishlistItemAdded => 'Added to wishlist',
            self::WishlistItemRemoved => 'Removed from wishlist',
            self::RecommendationShown => 'Recommendation shown',
            self::RecommendationClicked => 'Recommendation clicked',
            self::PaymentFailed => 'Payment failed',
            self::OrderConfirmed => 'Order confirmed',
            self::ShipmentCreated => 'Shipment created',
            self::ShipmentShipped => 'Shipment shipped',
            self::ShipmentDelivered => 'Shipment delivered',
            self::OrderDelivered => 'Order delivered',
        };
    }
}
