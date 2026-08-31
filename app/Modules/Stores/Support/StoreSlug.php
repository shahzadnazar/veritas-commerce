<?php

declare(strict_types=1);

namespace App\Modules\Stores\Support;

use Illuminate\Support\Str;

/**
 * Store address rules.
 *
 * A store's URL is the seller's accumulated search equity, so slugs are
 * normalised on the way in, checked against the routes they could shadow,
 * and never reused by a different seller.
 */
final class StoreSlug
{
    /**
     * Words a slug may not take, because /stores/{slug} sits under the
     * same namespace people expect these to mean.
     */
    public const RESERVED = [
        'admin', 'api', 'seller', 'sellers', 'store', 'stores', 'checkout',
        'cart', 'search', 'account', 'orders', 'login', 'logout', 'register',
        'password', 'verify-email', 'help', 'support', 'about', 'terms',
        'privacy', 'new', 'edit', 'create', 'delete', 'null', 'undefined',
    ];

    public static function normalise(string $input): string
    {
        return Str::of($input)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9]+/', '-')
            ->trim('-')
            ->limit(40, '')
            ->value();
    }

    public static function isReserved(string $slug): bool
    {
        return in_array(strtolower($slug), self::RESERVED, true);
    }

    public static function isWellFormed(string $slug): bool
    {
        return (bool) preg_match('/^[a-z0-9](?:[a-z0-9-]{1,38}[a-z0-9])$/', $slug);
    }
}
