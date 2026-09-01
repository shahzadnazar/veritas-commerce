<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Http\Controllers;

use App\Modules\Cart\Queries\ResolveCart;
use App\Modules\Orders\Models\MarketplaceOrder;
use App\Modules\Orders\Queries\BuildOrderDetail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The M4 handoff: an order exists, and nothing has been paid.
 *
 * Deliberately not a confirmation page. The customer's stock is held, the
 * seller orders exist and the totals are final, but no provider has taken
 * any money — and a page that said "thank you for your order" here would
 * be making a claim the platform cannot support. M5 attaches a provider
 * to this boundary; the page's job until then is to be honest about where
 * the purchase actually stands.
 *
 * Reachable by the customer who placed it, or — for a guest checkout —
 * by the browser whose session still holds the cart it came from. A
 * reference alone is never enough.
 */
final class PaymentPendingController
{
    public function __construct(
        private readonly BuildOrderDetail $detail,
        private readonly ResolveCart $carts,
    ) {}

    public function __invoke(Request $request, string $reference): Response
    {
        $order = $this->visibleOrFail($request, $reference);

        return Inertia::render('Checkout/PaymentPending', [
            'order' => ($this->detail)($order, withFinance: false),
            'paymentStatus' => [
                'state' => 'awaiting_payment',
                'headline' => 'Your order has been prepared, but payment has not yet been completed.',
                'detail' => 'The items are held for you until the time below. '
                    .'Nothing has been charged, and no card details have been taken.',
            ],
        ]);
    }

    /**
     * Whose order this is.
     *
     * For a signed-in customer, theirs. For a guest, the order their own
     * checkout produced — matched through the cart the session still
     * points at, which is the only durable link a guest has to it. A
     * reference typed by a stranger matches neither.
     */
    private function visibleOrFail(Request $request, string $reference): MarketplaceOrder
    {
        /** @var MarketplaceOrder|null $order */
        $order = MarketplaceOrder::query()->where('reference', $reference)->first();

        if ($order === null) {
            throw new NotFoundHttpException;
        }

        $user = $request->user('web');

        if ($user !== null && $order->user_id === (int) $user->getAuthIdentifier()) {
            return $order;
        }

        if ($order->user_id === null && $this->guestOwns($request, $order)) {
            return $order;
        }

        throw new NotFoundHttpException;
    }

    private function guestOwns(Request $request, MarketplaceOrder $order): bool
    {
        $token = $this->carts->tokenFor($request, create: false);

        if ($token === null || $order->checkout_attempt_id === null) {
            return false;
        }

        $cartId = DB::table('checkout_attempts')
            ->where('id', $order->checkout_attempt_id)
            ->value('cart_id');

        if ($cartId === null) {
            return false;
        }

        return DB::table('carts')
            ->where('id', $cartId)
            ->where('session_token', $token)
            ->exists();
    }
}
