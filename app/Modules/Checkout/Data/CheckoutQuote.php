<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Data;

use App\Modules\Cart\Data\CartIssue;
use App\Modules\Cart\Data\CartView;
use App\Support\Money;

/**
 * What this checkout actually costs, computed on the server.
 *
 * The authoritative figure. Nothing in it comes from the request: the
 * client sends a cart it owns and an address, and every number here is
 * derived from the live offer rows and the platform's own rules. A quote
 * assembled from a posted total is the single most expensive bug a
 * marketplace can ship, and the shape of this class is what makes it
 * impossible to write by accident — there is no constructor parameter for
 * a client to fill in.
 *
 * Blocking issues travel with it. §11 requires the checkout to refuse
 * rather than proceed on a stale cart, and the customer has to be told
 * which line and why.
 */
final readonly class CheckoutQuote
{
    /**
     * @param  array<int, CartIssue>  $issues
     */
    public function __construct(
        public CartView $cart,
        public Money $itemsTotal,
        public Money $shippingTotal,
        public Money $taxTotal,
        public Money $grandTotal,
        public array $issues,
        public string $currency,
    ) {}

    /** @return array<int, CartIssue> */
    public function blockingIssues(): array
    {
        return array_values(array_filter($this->issues, static fn (CartIssue $i): bool => $i->isBlocking()));
    }

    /**
     * Whether this quote may become an order.
     *
     * An empty cart is not buyable either — which is worth stating,
     * because an empty checkout that produced a zero-total order would
     * pass every money assertion in the suite.
     */
    public function isBuyable(): bool
    {
        return $this->cart->itemCount > 0 && $this->blockingIssues() === [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'cart' => $this->cart->toArray(),
            // Formatted here so no currency logic has to exist in React,
            // and in minor units so a test asserts on a number rather than
            // on a string somebody may reformat.
            'itemsTotal' => $this->itemsTotal->format(),
            'itemsTotalMinor' => $this->itemsTotal->minor,
            'shippingTotal' => $this->shippingTotal->format(),
            'shippingTotalMinor' => $this->shippingTotal->minor,
            'taxTotal' => $this->taxTotal->format(),
            'taxTotalMinor' => $this->taxTotal->minor,
            'grandTotal' => $this->grandTotal->format(),
            'grandTotalMinor' => $this->grandTotal->minor,
            'currency' => $this->currency,
            'buyable' => $this->isBuyable(),
            'issues' => array_map(static fn (CartIssue $i): array => $i->toArray(), $this->issues),
        ];
    }
}
