<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Concerns;

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
