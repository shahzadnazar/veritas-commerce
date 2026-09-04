<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Http\Controllers;

use App\Modules\Checkout\Queries\ResolveOwnOrder;
use App\Modules\Payments\Actions\PreparePayment;
use App\Modules\Payments\Exceptions\PaymentRefused;
use App\Modules\Payments\Exceptions\ProviderUnavailable;
use App\Modules\Payments\Queries\DescribePaymentState;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The customer's two payment endpoints: start one, and ask how it went.
 *
 * Neither can move an order. `prepare` asks the provider for a payment for
 * an amount this controller never sees — PreparePayment reads it from the
 * order — and `status` reports what verified provider events have already
 * written. There is no endpoint here a browser could call to say a payment
 * succeeded, which is the point: PaymentAuthorityTest fails the build if
 * any route reaches the paid transition, and the reason it exists is that
 * "the redirect said it worked" is how a marketplace ships goods for free.
 *
 * The client secret does leave here, because Stripe Elements cannot mount
 * without it. It authorises confirming one payment for one amount at one
 * provider; it cannot change that amount, and it is not a credential for
 * anything else. It is returned to the order's own customer only, never
 * logged, and never written into a page's shared props.
 */
final class OrderPaymentController
{
    public function __construct(
        private readonly ResolveOwnOrder $ownOrder,
        private readonly PreparePayment $prepare,
        private readonly DescribePaymentState $describe,
    ) {}

    /**
     * Start — or re-join — the payment for this order.
     *
     * Called on every load of the payment page and again when a customer
     * retries after a decline. Both are the same call: an open attempt is
     * returned as it stands rather than joined by a second, and a terminal
     * one is followed by a fresh attempt, so a customer reaching for
     * another card gets a new try without a second hold on the first.
     */
    public function prepare(Request $request, string $reference): JsonResponse
    {
        $order = ($this->ownOrder)($request, $reference);

        try {
            ['prepared' => $prepared, 'attempt' => $attempt] = ($this->prepare)($order);
        } catch (PaymentRefused $refused) {
            // The order cannot be paid — expired, cancelled, already paid.
            // 409 rather than 422: nothing about the request was wrong.
            return response()->json([
                'reason' => $refused->reason,
                'message' => $refused->getMessage(),
                'payment' => ($this->describe)($order->refresh()),
            ], 409);
        } catch (ProviderUnavailable) {
            /*
             * §66. An outage is not a decline: the order stays payable,
             * the stock stays held, and the customer is told to try again
             * shortly rather than that their card was refused.
             */
            return response()->json([
                'reason' => 'provider_unavailable',
                'message' => 'Payments are temporarily unavailable. Your order and items are still held — '
                    .'please try again in a moment.',
                'payment' => ($this->describe)($order),
            ], 503);
        }

        return response()->json([
            'provider' => $prepared->provider,
            'publishableKey' => (string) config('veritas.payments.stripe.key'),
            'clientSecret' => $prepared->clientSecret,
            'attemptPublicId' => $attempt->public_id,
            // Shown so the customer can check what they are about to be
            // charged. Taken from the order, like the charge itself.
            'amount' => [
                'minor' => $order->grand_total_minor,
                'currency' => $order->currency,
                'formatted' => Money::of($order->grand_total_minor, $order->currency)->format(),
            ],
            'returnUrl' => url("/checkout/{$order->reference}/payment"),
            'payment' => ($this->describe)($order->refresh()),
        ]);
    }

    /**
     * What the platform believes about this payment.
     *
     * Polled by the page after the customer confirms, because the answer
     * that matters arrives by webhook rather than in the browser. The
     * request carries no claim about the outcome and this reads none: a
     * `?redirect_status=succeeded` on the return URL is Stripe telling the
     * browser something, not the platform being told anything.
     */
    public function status(Request $request, string $reference): JsonResponse
    {
        $order = ($this->ownOrder)($request, $reference);

        return response()->json([
            'orderStatus' => $order->status->value,
            'payment' => ($this->describe)($order),
        ]);
    }
}
