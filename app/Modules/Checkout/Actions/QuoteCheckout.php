<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Actions;

use App\Modules\Cart\Data\CartIssue;
use App\Modules\Cart\Data\CartSellerGroup;
use App\Modules\Cart\Models\Cart;
use App\Modules\Cart\Queries\BuildCartView;
use App\Modules\Checkout\Data\CheckoutQuote;
use App\Support\Money;

/**
 * The authoritative price of a checkout.
 *
 * Built from the cart view, which re-prices every line from the live offer
 * and re-tests eligibility — so the quote is not "what the customer was
 * shown", it is "what is true now". §11: a price that moved, a seller that
 * was suspended or stock that ran out between the cart page and the
 * checkout button has to be caught here, not discovered after the money
 * moved.
 *
 * Shipping is charged per seller order, because that is what a marketplace
 * ships: two sellers are two parcels. The rate is configuration rather
 * than a literal, and tax is zero with the column carried through — M4
 * ships no tax engine, and inventing one would be worse than leaving the
 * seam honest and empty.
 */
final class QuoteCheckout
{
    public function __construct(private readonly BuildCartView $view) {}

    public function __invoke(?Cart $cart): CheckoutQuote
    {
        $view = ($this->view)($cart);
        $currency = $view->currency;

        $issues = [];

        foreach ($view->groups as $group) {
            foreach ($group->lines as $line) {
                foreach ($line->issues as $issue) {
                    $issues[] = $issue;
                }
            }
        }

        $itemsTotal = $view->subtotal;
        $shipping = $this->shippingFor($view->groups, $currency);
        $tax = Money::zero($currency);

        return new CheckoutQuote(
            cart: $view,
            itemsTotal: $itemsTotal,
            shippingTotal: $shipping,
            taxTotal: $tax,
            grandTotal: $itemsTotal->plus($shipping)->plus($tax),
            issues: $issues,
            currency: $currency,
        );
    }

    /**
     * @param  array<int, CartSellerGroup>  $groups
     */
    private function shippingFor(array $groups, string $currency): Money
    {
        $perSellerOrder = (int) config('veritas.checkout.shipping_per_seller_order_minor');

        if ($perSellerOrder <= 0 || $groups === []) {
            return Money::zero($currency);
        }

        return Money::of($perSellerOrder, $currency)->times(count($groups));
    }

    /**
     * The issues alone, for a caller that only needs to know whether to
     * refuse.
     *
     * @return array<int, CartIssue>
     */
    public function blockingIssuesFor(?Cart $cart): array
    {
        return $this($cart)->blockingIssues();
    }
}
