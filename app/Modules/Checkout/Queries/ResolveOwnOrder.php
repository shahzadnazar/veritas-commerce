<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Queries;

use App\Modules\Cart\Queries\ResolveCart;
use App\Modules\Orders\Models\MarketplaceOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Whose order this is — the one answer, used by every route that shows or
 * pays for one.
 *
 * A reference is a short, guessable string printed on emails and packing
 * slips, so it is never on its own a credential. For a signed-in customer
 * the order must be theirs; for a guest it must be the one their own
 * checkout produced, matched through the cart their session still points
 * at, which is the only durable link a guest has to it.
 *
 * Failure is 404 rather than 403 throughout: telling a stranger that an
 * order exists but is not theirs confirms the reference is real, and
 * references are sequential enough to walk.
 */
final class ResolveOwnOrder
{
    public function __construct(private readonly ResolveCart $carts) {}

    public function __invoke(Request $request, string $reference): MarketplaceOrder
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
