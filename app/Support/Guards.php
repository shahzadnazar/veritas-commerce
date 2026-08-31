<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Asks for a guard by name and gets back one that can actually hold a
 * session.
 *
 * Auth::guard() is typed to the bare Guard contract, which has no login()
 * or logout() — true of a token guard, false of both realms here. This
 * narrows it once, loudly, instead of every caller repeating the check or
 * assuming its way past it.
 */
final class Guards
{
    public static function session(string $name): StatefulGuard
    {
        $guard = Auth::guard($name);

        if (! $guard instanceof StatefulGuard) {
            throw new RuntimeException("The {$name} guard must be session-based.");
        }

        return $guard;
    }
}
