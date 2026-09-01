<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\Cart\Queries\CountCart;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Identity\Models\User;
use App\Modules\Sellers\Concerns\CurrentSeller;
use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * Props shared with every page.
 *
 * Platform identity comes from configuration, so no display name, support
 * address or currency is ever hard-coded in a component.
 */
final class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /**
     * Each area renders its own bundle, so a storefront visitor never
     * downloads seller or admin JavaScript and the portals can carry a
     * noindex header the storefront must not have.
     */
    public function rootView(Request $request): string
    {
        return match (true) {
            $request->is('admin', 'admin/*') => 'admin',
            $request->is('seller', 'seller/*') => 'seller',
            default => 'app',
        };
    }

    /** @return array<string, mixed> */
    public function share(Request $request): array
    {
        // Each realm is asked for by name. Never $request->user() with no
        // guard: the default guard shifts with context, and a customer and
        // a staff member are not interchangeable.
        /** @var User|null $user */
        $user = $request->user('web');

        /** @var AdminUser|null $admin */
        $admin = $request->user('admin');

        $membership = $user !== null ? CurrentSeller::membership() : null;

        // The account is resolved once and checked. A membership whose
        // account has gone is not a seller session — the foreign key makes
        // that unreachable, and the code should not pretend otherwise by
        // dereferencing blind.
        $sellerAccount = $membership?->sellerAccount;

        return [
            ...parent::share($request),

            'platform' => [
                'name' => config('veritas.identity.display_name'),
                'supportEmail' => config('veritas.identity.support_email'),
                'currency' => config('veritas.money.default_currency'),
            ],

            'auth' => [
                'user' => $user === null ? null : [
                    'publicId' => $user->public_id,
                    'name' => $user->fullName(),
                    'email' => $user->email,
                ],
                'seller' => $membership === null || $sellerAccount === null ? null : [
                    'publicId' => $sellerAccount->public_id,
                    'storeName' => $sellerAccount->store->name ?? $sellerAccount->legal_name,
                    'role' => $membership->role->value,
                ],
                'admin' => $admin === null ? null : [
                    'publicId' => $admin->public_id,
                    'name' => $admin->name,
                    'role' => $admin->role->value,
                ],
            ],

            /*
             * The header's cart count, from the database.
             *
             * Deferred behind a closure so the portals — which have no
             * cart link — never pay for the query, and skipped entirely
             * for a browser that has never had a cart.
             */
            'cart' => [
                'count' => fn (): int => $request->is('admin', 'admin/*', 'seller', 'seller/*')
                    ? 0
                    : app(CountCart::class)($request),
            ],

            'flash' => [
                'success' => fn (): ?string => $request->session()->get('success'),
                'error' => fn (): ?string => $request->session()->get('error'),
            ],
        ];
    }
}
