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

    public static function membership(): ?SellerMembership
    {
        $user = auth('web')->user();

        if ($user === null) {
            return null;
        }

        /** @var SellerMembership|null $membership */
        $membership = SellerMembership::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->whereNotNull('accepted_at')
            ->first();

        return $membership;
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
        $membership = self::membership();

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
