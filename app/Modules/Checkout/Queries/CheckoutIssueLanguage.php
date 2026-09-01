<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Queries;

use App\Modules\Cart\Data\CartIssue;
use App\Modules\Cart\Enums\CartIssueCode;
use App\Support\Money;

/**
 * What a cart issue says to the person who has to act on it.
 *
 * The codes exist so the application can branch. This exists so the
 * customer never sees one. A shopper told "OFFER_UNAVAILABLE" has been
 * shown the inside of the machine and given nothing they can do; the same
 * fact, written out, tells them their basket changed and why.
 *
 * One place, so the cart page, the checkout page and a failed checkout all
 * say the same thing about the same condition.
 */
final class CheckoutIssueLanguage
{
    /** @return array{code: string, blocking: bool, title: string, detail: string} */
    public static function describe(CartIssue $issue, string $currency = 'USD'): array
    {
        return [
            'code' => $issue->code->value,
            'blocking' => $issue->isBlocking(),
            'title' => self::title($issue->code),
            'detail' => self::detail($issue, $currency),
        ];
    }

    private static function title(CartIssueCode $code): string
    {
        return match ($code) {
            CartIssueCode::PriceChanged => 'Price changed',
            CartIssueCode::OutOfStock => 'Sold out',
            CartIssueCode::QuantityReduced => 'Quantity updated',
            CartIssueCode::OfferUnavailable => 'No longer available',
            CartIssueCode::SellerUnavailable => 'Seller unavailable',
            CartIssueCode::ProductUnavailable => 'Product unavailable',
            CartIssueCode::VariantUnavailable => 'Option unavailable',
            CartIssueCode::CurrencyMismatch => 'Currency mismatch',
        };
    }

    private static function detail(CartIssue $issue, string $currency): string
    {
        return match ($issue->code) {
            CartIssueCode::PriceChanged => self::priceChange($issue, $currency),

            CartIssueCode::OutOfStock => 'This item has sold out and cannot be bought right now. Remove it to continue.',

            CartIssueCode::QuantityReduced => $issue->available === null
                ? 'Fewer of these are available than you asked for.'
                : sprintf(
                    'Quantity updated because only %d %s currently available.',
                    $issue->available,
                    $issue->available === 1 ? 'item is' : 'items are',
                ),

            CartIssueCode::OfferUnavailable => 'This offer is no longer available and cannot be bought.',

            CartIssueCode::SellerUnavailable => 'The seller of this item is not trading at the moment, so it cannot be bought.',

            CartIssueCode::ProductUnavailable => 'This product has been withdrawn from the marketplace.',

            CartIssueCode::VariantUnavailable => 'The option you chose is no longer offered for this product.',

            CartIssueCode::CurrencyMismatch => 'This item is priced in a different currency and cannot be bought in this basket.',
        };
    }

    private static function priceChange(CartIssue $issue, string $currency): string
    {
        if ($issue->previousMinor === null || $issue->currentMinor === null) {
            return 'The price of this item has changed since you added it.';
        }

        return sprintf(
            'Price changed from %s to %s since you added it.',
            Money::of($issue->previousMinor, $currency)->format(),
            Money::of($issue->currentMinor, $currency)->format(),
        );
    }
}
