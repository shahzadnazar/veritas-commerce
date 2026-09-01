<?php

declare(strict_types=1);

namespace App\Modules\Cart\Queries;

use App\Modules\Cart\Enums\CartStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The number on the header's cart link.
 *
 * Server-authoritative, like everything else about a cart: the count comes
 * from the rows, never from something the browser has been keeping track
 * of. A client-side counter drifts the moment a line is dropped by a
 * revalidation, and a customer who sees "3" over an empty cart has been
 * lied to by their own interface.
 *
 * One query, joined rather than loaded, because this runs on every page in
 * the storefront and a cart badge is not worth a second round trip.
 */
final class CountCart
{
    public function __construct(private readonly ResolveCart $carts) {}

    /** Units in the live cart, or zero when there is not one. */
    public function __invoke(Request $request): int
    {
        $user = $request->user('web');
        $token = $user === null ? $this->carts->tokenFor($request, create: false) : null;

        if ($user === null && $token === null) {
            // A browser that has never added anything has no cart and
            // needs no query to prove it.
            return 0;
        }

        $query = DB::table('cart_items')
            ->join('carts', 'carts.id', '=', 'cart_items.cart_id')
            ->where('carts.status', CartStatus::Active->value);

        if ($user !== null) {
            $query->where('carts.user_id', $user->getAuthIdentifier());
        } else {
            $query->where('carts.session_token', $token);
        }

        return (int) $query->sum('cart_items.quantity');
    }
}
