<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Http\Controllers;

use App\Modules\Checkout\Queries\ResolveOwnOrder;
use App\Modules\Orders\Queries\BuildOrderDetail;
use App\Modules\Payments\Queries\DescribePaymentState;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where a customer pays for an order that already exists.
 *
 * The page is deliberately not a confirmation until it is one. The stock
 * is held, the seller orders exist and the totals are final — and until a
 * verified provider event says otherwise, nothing has been charged. What
 * the page says about the payment comes from DescribePaymentState, which
 * reads the attempt rows that verified events wrote, so the page cannot
 * claim more than the platform actually knows (§16).
 *
 * No client secret in these props. The page asks for one over its own
 * endpoint when the customer is ready to pay, so a secret is never baked
 * into HTML that a back button, a bfcache entry or a shared screenshot
 * could hold on to.
 *
 * Reachable by the customer who placed it, or — for a guest checkout — by
 * the browser whose session still holds the cart it came from. A reference
 * alone is never enough.
 */
final class PaymentPendingController
{
    public function __construct(
        private readonly BuildOrderDetail $detail,
        private readonly ResolveOwnOrder $ownOrder,
        private readonly DescribePaymentState $describe,
    ) {}

    public function __invoke(Request $request, string $reference): Response
    {
        $order = ($this->ownOrder)($request, $reference);

        return Inertia::render('Checkout/PaymentPending', [
            'order' => ($this->detail)($order, withFinance: false),
            'payment' => ($this->describe)($order),
            'endpoints' => [
                'prepare' => "/checkout/{$order->reference}/payment/prepare",
                'status' => "/checkout/{$order->reference}/payment/status",
            ],
        ]);
    }
}
