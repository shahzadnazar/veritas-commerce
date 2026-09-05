<?php

declare(strict_types=1);

namespace App\Modules\Orders\Support;

use App\Modules\Orders\Enums\MarketplaceOrderStatus;
use App\Modules\Orders\Models\MarketplaceOrder;

/**
 * Whether an order may still be paid for — the one place that decides.
 *
 * This exists to hold a distinction M5 made deliberately and M6 depends
 * on, because two very different things both get called "cancelled":
 *
 *  A. **A payment attempt failed or was cancelled at the provider.** One
 *     try at moving money did not work. That is not the customer
 *     abandoning the purchase — it is usually them reaching for a
 *     different card — so the order stays `pending_payment`, its stock
 *     stays held, and they may try again. Nothing in the payments module
 *     ends an order; `RecordPaymentFailure` closes the *attempt* and
 *     touches neither the order nor the reservation.
 *
 *  B. **The order itself was cancelled, or its checkout expired.** That
 *     is the business decision. The order stops being payable, the hold
 *     is released, no earning and no commission are realised, and
 *     `CancelUnpaidOrder` does all of it in one idempotent transaction.
 *
 * A Stripe PaymentIntent cancellation is an (A), never a (B). Treating
 * the provider's word as the marketplace's would destroy purchases the
 * customer had not abandoned and put their held stock back on the shelf
 * while they were still typing.
 *
 * The clock that ends an order is the checkout expiry that already
 * existed before payments did — one mechanism, not one per provider event.
 */
final class OrderPayability
{
    public const ALREADY_PAID = 'already_paid';

    public const NOT_PAYABLE = 'order_not_payable';

    public const EXPIRED = 'order_expired';

    /**
     * Why this order cannot be paid, or null if it can.
     *
     * Order matters: a cancelled order is refused as not payable rather
     * than as expired, because "we cancelled it" and "you ran out of
     * time" are different things to tell a customer.
     */
    public static function reasonNotPayable(MarketplaceOrder $order): ?string
    {
        if ($order->status === MarketplaceOrderStatus::Cancelled) {
            return self::NOT_PAYABLE;
        }

        if ($order->status !== MarketplaceOrderStatus::PendingPayment) {
            // Everything past pending_payment has been paid for already,
            // one way or another.
            return self::ALREADY_PAID;
        }

        if ($order->payment_expires_at !== null && $order->payment_expires_at->isPast()) {
            return self::EXPIRED;
        }

        return null;
    }

    public static function isPayable(MarketplaceOrder $order): bool
    {
        return self::reasonNotPayable($order) === null;
    }
}
