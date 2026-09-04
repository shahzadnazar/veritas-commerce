<?php

declare(strict_types=1);

namespace App\Modules\Payments\Queries;

use App\Modules\Orders\Enums\MarketplaceOrderStatus;
use App\Modules\Orders\Models\MarketplaceOrder;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Payments\Support\PaymentLanguage;

/**
 * Where a customer's payment stands, in words they can act on.
 *
 * The single source for the payment page, the polling endpoint and the
 * prepare response, so all three cannot disagree — a page that said
 * "processing" while the poll said "failed" would have the customer
 * waiting for a payment that had already been refused.
 *
 * Read from the database, never from the browser. §16: a client may claim
 * anything about a redirect it was given; what the platform believes about
 * a payment comes from the attempt row that verified provider events wrote.
 *
 * Nothing here carries a provider decline code, a client secret, a charge
 * id or a raw provider payload. The customer gets the state and a sentence;
 * §53 keeps the provider's own wording for operators.
 */
final class DescribePaymentState
{
    /**
     * @return array{
     *     state: string,
     *     headline: string,
     *     detail: string,
     *     canPay: bool,
     *     canRetry: bool,
     *     isPaid: bool,
     *     attemptStatus: string|null,
     *     attemptLabel: string|null,
     *     expiresAt: string|null,
     * }
     */
    public function __invoke(MarketplaceOrder $order): array
    {
        $attempt = $this->latestAttempt($order);

        $base = [
            'attemptStatus' => $attempt?->status->value,
            'attemptLabel' => $attempt?->status->label(),
            'expiresAt' => $order->payment_expires_at?->toIso8601String(),
        ];

        // The order, not the attempt, decides whether money arrived. An
        // attempt is one try; the order is the fact.
        if ($order->status !== MarketplaceOrderStatus::PendingPayment) {
            return array_merge($base, $this->closedOrder($order));
        }

        if ($order->payment_expires_at !== null && $order->payment_expires_at->isPast()) {
            return array_merge($base, [
                'state' => 'expired',
                'headline' => 'The payment window for this order has closed.',
                'detail' => 'The items were held for a limited time and have been released. '
                    .'You can add them to your basket again if they are still available.',
                'canPay' => false,
                'canRetry' => false,
                'isPaid' => false,
            ]);
        }

        return array_merge($base, $this->openOrder($attempt));
    }

    /**
     * @return array{state: string, headline: string, detail: string, canPay: bool, canRetry: bool, isPaid: bool}
     */
    private function closedOrder(MarketplaceOrder $order): array
    {
        if ($order->status === MarketplaceOrderStatus::Cancelled) {
            return [
                'state' => 'cancelled',
                'headline' => 'This order was cancelled.',
                'detail' => 'Nothing has been charged. If you were charged, contact support and quote '
                    .'the order reference.',
                'canPay' => false,
                'canRetry' => false,
                'isPaid' => false,
            ];
        }

        return [
            'state' => 'paid',
            'headline' => 'Payment received. Your order is confirmed.',
            'detail' => 'Each seller has been notified and will prepare your items. '
                .'A confirmation is on its way to your email.',
            'canPay' => false,
            'canRetry' => false,
            'isPaid' => true,
        ];
    }

    /**
     * @return array{state: string, headline: string, detail: string, canPay: bool, canRetry: bool, isPaid: bool}
     */
    private function openOrder(?PaymentAttempt $attempt): array
    {
        if ($attempt === null) {
            return [
                'state' => 'awaiting_payment',
                'headline' => 'Your order is ready to pay for.',
                'detail' => 'The items are held for you until the time below. '
                    .'Nothing has been charged yet.',
                'canPay' => true,
                'canRetry' => false,
                'isPaid' => false,
            ];
        }

        return match ($attempt->status) {
            /*
             * Succeeded on the attempt but the order is still pending is
             * not "paid" — it is the few hundred milliseconds between the
             * provider's answer and this platform's verification, or a
             * verification that failed. Either way the honest word is
             * "processing", never "confirmed".
             */
            PaymentAttemptStatus::Succeeded, PaymentAttemptStatus::Processing => [
                'state' => 'processing',
                'headline' => 'Your payment is being processed.',
                'detail' => 'This usually takes a few seconds. You do not need to pay again — '
                    .'this page will update on its own.',
                'canPay' => false,
                'canRetry' => false,
                'isPaid' => false,
            ],

            PaymentAttemptStatus::RequiresAction => [
                'state' => 'requires_action',
                'headline' => 'Your bank needs to confirm this payment.',
                'detail' => 'Finish the confirmation your bank asked for to complete the purchase.',
                'canPay' => true,
                'canRetry' => false,
                'isPaid' => false,
            ],

            PaymentAttemptStatus::Failed, PaymentAttemptStatus::Cancelled => [
                'state' => 'failed',
                'headline' => 'Your payment did not go through.',
                // The platform's wording, derived from the failure code but
                // never quoting it — §53. Your items are still yours: §20
                // holds the reservation through a decline.
                'detail' => PaymentLanguage::forCode($attempt->failure_code).' Your items are still held for you.',
                'canPay' => true,
                'canRetry' => true,
                'isPaid' => false,
            ],

            default => [
                'state' => 'awaiting_payment',
                'headline' => 'Your order is ready to pay for.',
                'detail' => 'The items are held for you until the time below. '
                    .'Nothing has been charged yet.',
                'canPay' => true,
                'canRetry' => false,
                'isPaid' => false,
            ],
        };
    }

    /**
     * What a seller is allowed to know about the customer's payment.
     *
     * Two facts: whether the money cleared, and when. A seller needs the
     * first to know they may ship and the second to reconcile; they have
     * no business with the provider's reference, the card's description,
     * a decline code, or anything else about how their customer paid —
     * §27 and §51 draw that line, and the shape of this return value is
     * what keeps it drawn.
     *
     * @return array{isPaid: bool, paidAt: string|null}
     */
    public function forSeller(MarketplaceOrder $order): array
    {
        /** @var Payment|null $payment */
        $payment = Payment::query()
            ->where('marketplace_order_id', $order->id)
            ->orderByDesc('id')
            ->first();

        return [
            'isPaid' => $order->status !== MarketplaceOrderStatus::PendingPayment
                && $order->status !== MarketplaceOrderStatus::Cancelled,
            'paidAt' => $payment?->captured_at?->toIso8601String(),
        ];
    }

    private function latestAttempt(MarketplaceOrder $order): ?PaymentAttempt
    {
        /** @var PaymentAttempt|null $attempt */
        $attempt = PaymentAttempt::query()
            ->where('marketplace_order_id', $order->id)
            ->orderByDesc('id')
            ->first();

        return $attempt;
    }
}
