<?php

declare(strict_types=1);

namespace App\Modules\Cart\Listeners;

use App\Modules\Cart\Actions\MergeCarts;
use App\Modules\Cart\Enums\CartStatus;
use App\Modules\Cart\Models\Cart;
use App\Modules\Cart\Queries\ResolveCart;
use App\Modules\Cart\Support\MergeNotice;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;

/**
 * The moment a shopper stops being anonymous.
 *
 * Listens to the framework's own Login event rather than living in the
 * sign-in controller, so registration, a password reset that signs the
 * customer back in, and a remembered session all get the same behaviour —
 * there is no second sign-in path to forget about.
 *
 * Runs synchronously and inside the request that owns the session, because
 * the anonymous cart is identified by a session value that a queued job
 * would never see.
 */
final class AdoptCartOnLogin
{
    public function __construct(
        private readonly ResolveCart $carts,
        private readonly MergeCarts $merge,
    ) {}

    public function handle(Login $event): void
    {
        // Customer sign-in only. An admin signing in has no storefront
        // cart, and the two realms deliberately share nothing.
        if ($event->guard !== 'web') {
            return;
        }

        $request = request();

        if (! $request->hasSession()) {
            return;
        }

        $token = $this->carts->tokenFor($request, create: false);

        if ($token === null) {
            return;
        }

        /** @var Cart|null $anonymous */
        $anonymous = Cart::query()
            ->where('session_token', $token)
            ->where('status', CartStatus::Active->value)
            ->first();

        // The token stops identifying anything the moment the browser has
        // an account behind it, whether or not there was a cart on it.
        $this->carts->forgetToken($request);

        if ($anonymous === null) {
            return;
        }

        $userId = (int) $event->user->getAuthIdentifier();

        /** @var Cart|null $existing */
        $existing = Cart::query()
            ->where('user_id', $userId)
            ->where('status', CartStatus::Active->value)
            ->first();

        if ($existing === null) {
            $this->merge->adopt($anonymous, $userId);

            return;
        }

        MergeNotice::remember($request, ($this->merge)($anonymous, $existing));
    }
}
