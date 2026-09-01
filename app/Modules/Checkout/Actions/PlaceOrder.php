<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Actions;

use App\Modules\Cart\Data\CartIssue;
use App\Modules\Cart\Data\CartLine;
use App\Modules\Cart\Data\CartSellerGroup;
use App\Modules\Cart\Enums\CartIssueCode;
use App\Modules\Cart\Enums\CartStatus;
use App\Modules\Cart\Models\Cart;
use App\Modules\Checkout\Data\CheckoutQuote;
use App\Modules\Checkout\Data\ShippingAddress;
use App\Modules\Checkout\Enums\CheckoutStatus;
use App\Modules\Checkout\Exceptions\CheckoutRefused;
use App\Modules\Checkout\Models\CheckoutAttempt;
use App\Modules\Commission\Actions\BuildCommissionSnapshot;
use App\Modules\Orders\Actions\AllocateReference;
use App\Modules\Orders\Enums\MarketplaceOrderStatus;
use App\Modules\Orders\Enums\SellerOrderStatus;
use App\Modules\Orders\Models\MarketplaceOrder;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderStatusHistory;
use App\Modules\Orders\Models\SellerOrder;
use App\Support\Money;
use App\Support\Reference;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One checkout becomes one order, split into one order per seller.
 *
 *   VC-24081
 *   ├── VC-24081-01  Seller A
 *   └── VC-24081-02  Seller B
 *
 * §18 to §24. The parent is what the customer bought and pays for once;
 * each child is what one seller owes, ships and is eventually paid for,
 * with its own status, its own commission and its own fulfilment clock.
 * A marketplace that recorded only the parent could not pay two sellers
 * independently, and one that recorded only the children could not show
 * the customer a single order.
 *
 * Everything financial is snapshotted. Not one figure on an order item is
 * ever recomputed from a live offer or a current commission rate — that is
 * the single mistake that would make every historical number in the
 * marketplace wrong the next time a rate moves.
 *
 * IDEMPOTENT, like the attempt that precedes it: a retry returns the order
 * that already exists rather than making a second one. The attempt row
 * carries the decision, and it is locked before it is read.
 *
 * The order is created pending payment and holding its reservations. It
 * takes no money — M5 does that — but the boundary is already here: stock
 * is held, not sold, until payment captures.
 */
final class PlaceOrder
{
    public function __construct(
        private readonly QuoteCheckout $quote,
        private readonly AllocateReference $references,
        private readonly BuildCommissionSnapshot $commission,
    ) {}

    /**
     * @throws CheckoutRefused
     */
    public function __invoke(CheckoutAttempt $attempt): MarketplaceOrder
    {
        return DB::transaction(function () use ($attempt): MarketplaceOrder {
            /** @var CheckoutAttempt $locked */
            $locked = CheckoutAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();

            /*
             * The already-decided early return §16 asks for. Locked first,
             * so two workers handed the same attempt cannot both find it
             * undecided — the second waits, then sees the first's order.
             */
            if ($locked->marketplace_order_id !== null) {
                /** @var MarketplaceOrder $existing */
                $existing = MarketplaceOrder::query()->findOrFail($locked->marketplace_order_id);

                return $existing;
            }

            if ($locked->status !== CheckoutStatus::Reserved) {
                throw new CheckoutRefused(
                    'That checkout is no longer open.',
                    reason: 'attempt_not_open',
                );
            }

            if ($locked->hasExpired()) {
                throw new CheckoutRefused(
                    'That checkout timed out and its items were released.',
                    reason: 'attempt_expired',
                );
            }

            $cart = $locked->cart_id === null ? null : Cart::query()->find($locked->cart_id);
            $quote = ($this->quote)($cart);

            $this->guardAgainstDrift($locked, $quote);

            $order = $this->createOrder($locked, $quote);

            if ($cart !== null) {
                // Kept, never reused: a converted cart is the evidence
                // behind the order.
                $cart->forceFill(['status' => CartStatus::Converted->value])->save();
            }

            $locked->forceFill([
                'marketplace_order_id' => $order->id,
                'status' => CheckoutStatus::Completed->value,
                'completed_at' => now(),
            ])->save();

            return $order;
        });
    }

    /**
     * The last look before money is committed to.
     *
     * §11 requires a revalidation at order creation, not only at the start
     * of the checkout — a seller can be suspended, or a product pulled, in
     * the seconds between. The reservation guarantees the units are still
     * there; it guarantees nothing about whether they may still be sold.
     *
     * A total that moved is refused rather than absorbed. The customer
     * agreed to a number, and an order written for a different one is not
     * the order they placed, whichever direction it moved in.
     */
    private function guardAgainstDrift(CheckoutAttempt $attempt, CheckoutQuote $quote): void
    {
        if ($quote->cart->itemCount === 0) {
            throw CheckoutRefused::cartIsEmpty();
        }

        $blocking = array_values(array_filter(
            $quote->blockingIssues(),
            // Stock is held by this very checkout, so an availability
            // figure that no longer covers the cart is this attempt's own
            // reservation being seen from outside it — not a shortage.
            static fn (CartIssue $issue): bool => ! in_array(
                $issue->code,
                [CartIssueCode::OutOfStock, CartIssueCode::QuantityReduced],
                true,
            ),
        ));

        if ($blocking !== []) {
            throw CheckoutRefused::cartIsNotBuyable($blocking);
        }

        if ($quote->grandTotal->minor !== $attempt->grand_total_minor) {
            throw new CheckoutRefused(
                'The price of something in your basket changed while you were paying.',
                reason: 'price_moved',
            );
        }
    }

    private function createOrder(CheckoutAttempt $attempt, CheckoutQuote $quote): MarketplaceOrder
    {
        $currency = $quote->currency;
        $reference = $this->references->orderReference();
        $address = ShippingAddress::fromArray($attempt->shipping_address ?? []);

        $order = MarketplaceOrder::query()->create([
            'reference' => $reference,
            'user_id' => $attempt->user_id,
            'email' => (string) $attempt->email,
            'status' => MarketplaceOrderStatus::PendingPayment->value,
            'currency' => $currency,
            // Written from the quote now and reconciled against the seller
            // orders below, so a rollup that disagrees fails here rather
            // than in a payout three months later.
            'items_total_minor' => $quote->itemsTotal->minor,
            'shipping_total_minor' => $quote->shippingTotal->minor,
            'tax_total_minor' => $quote->taxTotal->minor,
            'discount_total_minor' => 0,
            'grand_total_minor' => $quote->grandTotal->minor,
            'placed_at' => now(),
            'checkout_attempt_id' => $attempt->id,
            'payment_expires_at' => $attempt->expires_at,
            'reservation_reference' => $attempt->reservationReference(),
            'ship_name' => $address->name,
            'ship_line1' => $address->line1,
            'ship_line2' => $address->line2,
            'ship_city' => $address->city,
            'ship_state' => $address->state,
            'ship_postcode' => $address->postcode,
            'ship_country' => $address->country,
            'ship_phone' => $address->phone,
        ]);

        $itemsTotal = Money::zero($currency);
        $shippingTotal = Money::zero($currency);
        $position = 0;

        foreach ($quote->cart->groups as $group) {
            $position++;
            $sellerOrder = $this->createSellerOrder($order, $group, $position, $currency);

            $itemsTotal = $itemsTotal->plus(Money::of($sellerOrder->items_total_minor, $currency));
            $shippingTotal = $shippingTotal->plus(Money::of($sellerOrder->shipping_total_minor, $currency));

            OrderStatusHistory::query()->create([
                'seller_order_id' => $sellerOrder->id,
                'from_status' => null,
                'to_status' => SellerOrderStatus::PendingPayment->value,
                'actor_type' => 'system',
                'note' => 'Order placed, awaiting payment.',
                'created_at' => now(),
            ]);
        }

        $this->reconcile($order, $itemsTotal, $shippingTotal);

        OrderStatusHistory::query()->create([
            'marketplace_order_id' => $order->id,
            'from_status' => null,
            'to_status' => MarketplaceOrderStatus::PendingPayment->value,
            'actor_type' => 'system',
            'note' => 'Order placed, awaiting payment.',
            'created_at' => now(),
        ]);

        return $order->refresh();
    }

    /**
     * One seller's half of the order, and the items under it.
     *
     * Position is the seller's place in the parent, and it is what the
     * "-01" suffix is made of. The groups arrive sorted by seller id, so
     * the same basket always numbers the same way.
     */
    private function createSellerOrder(
        MarketplaceOrder $order,
        CartSellerGroup $group,
        int $position,
        string $currency,
    ): SellerOrder {
        $shipping = Money::of(
            (int) config('veritas.checkout.shipping_per_seller_order_minor'),
            $currency,
        );

        $sellerOrder = SellerOrder::query()->create([
            'reference' => Reference::subOrder($order->reference, $position),
            'marketplace_order_id' => $order->id,
            'seller_account_id' => $group->sellerAccountId,
            'store_id' => $group->storeId,
            'position' => $position,
            'status' => SellerOrderStatus::PendingPayment->value,
            'currency' => $currency,
        ]);

        $items = Money::zero($currency);
        $commission = Money::zero($currency);
        $earning = Money::zero($currency);

        foreach ($group->lines as $line) {
            $item = $this->createItem($sellerOrder, $line, $currency);

            $items = $items->plus(Money::of($item->line_total_minor, $currency));
            $commission = $commission->plus(Money::of($item->commission_amount_minor, $currency));
            $earning = $earning->plus(Money::of($item->seller_earning_amount_minor, $currency));
        }

        /*
         * Rolled up from the items' own snapshots, never recomputed from a
         * rate. The database checks both sums here — the totals and the
         * commission split — so a rollup written any other way fails.
         */
        $sellerOrder->forceFill([
            'items_total_minor' => $items->minor,
            'shipping_total_minor' => $shipping->minor,
            'tax_total_minor' => 0,
            'discount_total_minor' => 0,
            'order_total_minor' => $items->plus($shipping)->minor,
            'commission_total_minor' => $commission->minor,
            'seller_earning_total_minor' => $earning->minor,
        ])->save();

        return $sellerOrder;
    }

    /**
     * The line, frozen.
     *
     * Every descriptive value the customer saw is copied, not referenced:
     * the product may be retitled, the store renamed, the offer archived,
     * and the receipt still has to say what it said on the day.
     */
    private function createItem(SellerOrder $sellerOrder, CartLine $line, string $currency): OrderItem
    {
        $lineTotal = Money::of($line->lineTotal->minor, $currency);

        $snapshot = ($this->commission)(
            lineTotal: $lineTotal,
            sellerAccountId: $sellerOrder->seller_account_id,
            categoryId: $line->categoryId,
        );

        return OrderItem::query()->create([
            'seller_order_id' => $sellerOrder->id,
            'offer_id' => $line->offerId,
            'product_id' => $line->productId,
            'product_variant_id' => $line->variantId,
            'product_title' => $line->productTitle,
            'brand_name_snapshot' => $line->brandName,
            'store_name_snapshot' => $line->storeName,
            'product_slug_snapshot' => $line->productSlug,
            'variant_name' => $line->variantName,
            'seller_sku' => $line->sellerSku,
            'currency' => $currency,
            'unit_price_snapshot_minor' => $line->unitPrice->minor,
            'quantity' => $line->quantity,
            'discount_snapshot_minor' => 0,
            'line_total_minor' => $lineTotal->minor,
            ...$snapshot->toOrderItemColumns(),
        ]);
    }

    /**
     * The reconciliation §24 asks for, asserted rather than reported.
     *
     * The parent's totals must equal the sum of its children's. A job that
     * checked this afterwards would find a discrepancy somebody then has
     * to explain; failing the transaction means the discrepancy never
     * exists.
     */
    private function reconcile(MarketplaceOrder $order, Money $items, Money $shipping): void
    {
        if ($items->minor !== $order->items_total_minor || $shipping->minor !== $order->shipping_total_minor) {
            throw new RuntimeException(sprintf(
                'Order %s does not reconcile with its seller orders: items %d vs %d, shipping %d vs %d.',
                $order->reference,
                $items->minor,
                $order->items_total_minor,
                $shipping->minor,
                $order->shipping_total_minor,
            ));
        }
    }
}
