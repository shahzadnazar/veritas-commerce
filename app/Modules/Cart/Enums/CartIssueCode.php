<?php

declare(strict_types=1);

namespace App\Modules\Cart\Enums;

/**
 * What changed under a customer since they added something.
 *
 * A cart is a record of intent, not a promise: prices move, sellers get
 * suspended and stock runs out between adding and paying. Every one of
 * those is a distinct thing to tell someone, so each is its own code
 * rather than a generic "please review your cart".
 *
 * Nothing here ever substitutes another seller's offer. The customer chose
 * a particular seller; quietly swapping them is how a marketplace loses
 * the trust that makes it a marketplace.
 */
enum CartIssueCode: string
{
    case PriceChanged = 'PRICE_CHANGED';
    case OutOfStock = 'OUT_OF_STOCK';
    case QuantityReduced = 'QUANTITY_REDUCED';
    case OfferUnavailable = 'OFFER_UNAVAILABLE';
    case SellerUnavailable = 'SELLER_UNAVAILABLE';
    case ProductUnavailable = 'PRODUCT_UNAVAILABLE';
    case VariantUnavailable = 'VARIANT_UNAVAILABLE';
    case CurrencyMismatch = 'CURRENCY_MISMATCH';

    /**
     * Whether the line cannot be bought at all, as opposed to needing the
     * customer to notice something.
     *
     * A price change is acknowledged and proceeds; an unavailable offer
     * cannot. Checkout blocks on the second kind only.
     */
    public function isBlocking(): bool
    {
        return $this !== self::PriceChanged;
    }

    public function label(): string
    {
        return match ($this) {
            self::PriceChanged => 'The price has changed',
            self::OutOfStock => 'Out of stock',
            self::QuantityReduced => 'Fewer are available than you asked for',
            self::OfferUnavailable => 'This listing is no longer available',
            self::SellerUnavailable => 'This seller is not trading at the moment',
            self::ProductUnavailable => 'This product is no longer for sale',
            self::VariantUnavailable => 'That option is no longer available',
            self::CurrencyMismatch => 'This listing is priced in another currency',
        };
    }
}
