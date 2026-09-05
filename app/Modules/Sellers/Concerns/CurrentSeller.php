<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Concerns;

use App\Modules\Sellers\Enums\SellerPermission;
use App\Modules\Sellers\Enums\SellerRole;
use App\Modules\Sellers\Models\SellerMembership;

/**
 * The seller the current actor is scoped to, and with what role.
 *
 * Authorisation asks "which seller is this actor acting for", never "is this
 * user the owner of the seller in the URL" — which is why adding staff to a
 * store later changes data rather than the authorisation model.
 *
 * Nothing here trusts a seller_id from the request. The scope is derived
 * from the authenticated user's membership, server-side, every time.
 */
final class CurrentSeller
{
    private static ?int $overrideId = null;

    private static bool $overridden = false;

    public static function id(): ?int
    {
        if (self::$overridden) {
            return self::$overrideId;
        }

        return self::membership()?->seller_account_id;
    }

    public static function role(): ?SellerRole
    {
        return self::membership()?->role;
    }

    /**
     * Where the resolved membership is remembered for the rest of a request.
     *
     * On the Request object, and that choice is the whole point. It used to
     * be memoised into a controller property, which looked equivalent and
     * was not: Laravel caches a controller instance on the Route, and a
     * Route outlives the request that used it. Under php-fpm the process
     * ends with the request and nothing is ever noticed; under a runtime
     * that keeps the application alive between requests — Octane,
     * RoadRunner, Swoole — the second seller to reach that controller is
     * served the first seller's membership and reads their payouts. M9
     * reproduced exactly that, two requests, one leak.
     *
     * A Request, by contrast, is genuinely one request under every runtime.
     */
    private const CACHE_KEY = 'veritas.current_seller_membership';

    /**
     * The acting user's membership, resolved once per request.
     *
     * Resolved once because several screens ask four or five times and the
     * answer cannot change underneath them; `flushCache()` below is bound
     * to membership writes so that even that assumption is enforced rather
     * than assumed.
     */
    public static function membership(): ?SellerMembership
    {
        $user = auth('web')->user();

        if ($user === null) {
            return null;
        }

        $userId = (int) $user->getAuthIdentifier();
        $request = request();
        $cached = $request->attributes->get(self::CACHE_KEY);

        // Keyed by user as well as scoped to the request, because a test —
        // and an Octane worker — can put two different people through one
        // application without either of them being wrong to expect their
        // own data.
        if (is_array($cached) && $cached['user'] === $userId) {
            return $cached['membership'];
        }

        /** @var SellerMembership|null $membership */
        $membership = SellerMembership::query()
            ->where('user_id', $userId)
            ->whereNotNull('accepted_at')
            ->first();

        $request->attributes->set(self::CACHE_KEY, ['user' => $userId, 'membership' => $membership]);

        return $membership;
    }

    /**
     * Forget the resolved membership.
     *
     * Bound to membership writes in AppServiceProvider, so that a request
     * which changes a role — accepting an invitation, promoting a member,
     * removing one — cannot go on answering authorisation questions from
     * the membership it had before it made the change.
     */
    public static function flushCache(): void
    {
        request()->attributes->remove(self::CACHE_KEY);
    }

    public static function isActing(): bool
    {
        return self::id() !== null;
    }

    /**
     * Whether the current actor holds a capability for the seller they are
     * acting as.
     *
     * Suspension is answered here, once, rather than at each call site: a
     * suspended seller keeps read access — they must still see the orders
     * they owe customers — and loses every write.
     */
    public static function can(SellerPermission $permission): bool
    {
        return self::allows(self::membership(), $permission);
    }

    /**
     * The same question, asked of a membership the caller already has.
     *
     * `can()` resolves the membership every time it is called, which is
     * fine for one check in a middleware and wasteful for a screen that
     * asks four. A page that needs several answers resolves the membership
     * once and comes here — one place still decides, and the suspension
     * rule below is not duplicated at the call site.
     */
    public static function allows(?SellerMembership $membership, SellerPermission $permission): bool
    {
        if ($membership === null) {
            return false;
        }

        if (! $membership->role->can($permission)) {
            return false;
        }

        $seller = $membership->sellerAccount;

        if ($seller === null) {
            return false;
        }

        return $seller->status->canSell() || ! self::isWrite($permission);
    }

    /** Capabilities that change something, and so stop at suspension. */
    private static function isWrite(SellerPermission $permission): bool
    {
        return in_array($permission, [
            SellerPermission::StoreManage,
            SellerPermission::MembersManage,
            SellerPermission::CatalogManage,
            SellerPermission::InventoryManage,
            SellerPermission::OrdersManage,
            SellerPermission::PayoutsRequest,
            SellerPermission::PayoutAccountManage,
        ], true);
    }

    /**
     * Run a callback scoped to a specific seller.
     *
     * Used by queue jobs and console commands, which have no session. Not a
     * way for a request to choose its own tenant.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function actingAs(?int $sellerAccountId, callable $callback): mixed
    {
        $previousId = self::$overrideId;
        $previouslyOverridden = self::$overridden;

        self::$overrideId = $sellerAccountId;
        self::$overridden = true;

        try {
            return $callback();
        } finally {
            self::$overrideId = $previousId;
            self::$overridden = $previouslyOverridden;
        }
    }

    /** Escape the tenant scope explicitly — admin queries and reconciliation only. */
    public static function withoutScope(callable $callback): mixed
    {
        return self::actingAs(null, $callback);
    }
}
