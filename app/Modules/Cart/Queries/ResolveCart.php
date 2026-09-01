<?php

declare(strict_types=1);

namespace App\Modules\Cart\Queries;

use App\Modules\Cart\Enums\CartStatus;
use App\Modules\Cart\Models\Cart;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Whose cart this is.
 *
 * Ownership comes from the authenticated session or the browser's own
 * token, and from nowhere else. §4 is explicit and it is the whole
 * security model for the cart: there is no cart id in any request, so
 * there is no id for anyone to manipulate.
 *
 * The anonymous token is a random ULID in the session — the same
 * privacy-conscious identifier the analytics use, and for the same
 * reason: nothing about the browser is measured, so nothing about it can
 * be used to recognise the person later.
 */
final class ResolveCart
{
    private const SESSION_KEY = 'veritas_cart_token';

    /** The live cart, or null when the customer has never added anything. */
    public function existing(Request $request): ?Cart
    {
        $user = $request->user('web');

        if ($user !== null) {
            return Cart::query()
                ->where('user_id', $user->getAuthIdentifier())
                ->where('status', CartStatus::Active->value)
                ->first();
        }

        $token = $this->tokenFor($request, create: false);

        if ($token === null) {
            return null;
        }

        return Cart::query()
            ->where('session_token', $token)
            ->where('status', CartStatus::Active->value)
            ->first();
    }

    /**
     * The live cart, created on first need.
     *
     * `firstOrCreate` under the partial unique index rather than a
     * read-then-write: two tabs adding at once would otherwise both see
     * nothing and both insert, and one would fail on the constraint.
     */
    public function orCreate(Request $request): Cart
    {
        $user = $request->user('web');

        return DB::transaction(function () use ($request, $user): Cart {
            if ($user !== null) {
                $cart = Cart::query()->firstOrCreate(
                    [
                        'user_id' => $user->getAuthIdentifier(),
                        'status' => CartStatus::Active->value,
                    ],
                    ['last_activity_at' => now()],
                );

                return $cart->refresh();
            }

            $token = $this->tokenFor($request, create: true);

            $cart = Cart::query()->firstOrCreate(
                ['session_token' => $token, 'status' => CartStatus::Active->value],
                ['last_activity_at' => now()],
            );

            return $cart->refresh();
        });
    }

    /**
     * The anonymous browser's cart token.
     *
     * Random, stored in the session, and meaningless outside it. A
     * customer who clears their cookies is a new browser to us, which is
     * the correct behaviour rather than a limitation to engineer around.
     */
    public function tokenFor(Request $request, bool $create = true): ?string
    {
        if (! $request->hasSession()) {
            return null;
        }

        $session = $request->session();
        $existing = $session->get(self::SESSION_KEY);

        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        if (! $create) {
            return null;
        }

        $token = (string) Str::ulid();
        $session->put(self::SESSION_KEY, $token);

        return $token;
    }

    public function forgetToken(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->forget(self::SESSION_KEY);
        }
    }
}
