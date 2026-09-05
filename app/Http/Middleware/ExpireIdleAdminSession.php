<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A console session that has been sitting idle stops being one.
 *
 * The platform console can approve sellers, settle payouts and read
 * verification paperwork, so it is worth less time unattended than a
 * shopping session is. `ADMIN_SESSION_LIFETIME` has been in .env.example
 * since M1, next to a comment promising exactly that — and until M9 it
 * was read by nothing, so administrators quietly had the ordinary 120
 * minutes. This is the code that makes the promise true.
 *
 * Idle time rather than absolute age: an administrator working through a
 * queue of applications should not be thrown out mid-review, and one who
 * walked away from an unlocked laptop should be. Every admin request
 * touches the stamp, so the clock only runs while nothing is happening.
 *
 * Only the admin guard is logged out. A member of staff who also shops on
 * the same browser keeps their customer session, because taking that away
 * would be surprising and buys nothing: the customer session was never the
 * privileged one.
 */
final class ExpireIdleAdminSession
{
    /** Where the stamp lives. Namespaced so nothing else writes to it. */
    public const STAMP = 'admin.last_activity_at';

    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::guard('admin')->check()) {
            return $next($request);
        }

        $limit = (int) config('veritas.admin.session_lifetime_minutes') * 60;
        $last = $request->session()->get(self::STAMP);

        // The framework clock, not time(): Carbon is what the rest of the
        // application reads, what a test can travel, and what a future
        // clock abstraction would control. A middleware on its own clock
        // is a middleware nobody can test.
        $now = now()->getTimestamp();

        if (is_int($last) && $now - $last > $limit) {
            /*
             * The admin guard only. Logging out and regenerating means the
             * next request arrives unauthenticated with a new session id,
             * so a session left open on a shared machine is not a way back
             * in — and neither is the old identifier.
             */
            Auth::guard('admin')->logout();
            $request->session()->forget(self::STAMP);
            $request->session()->regenerate();

            return redirect()
                ->route('admin.login')
                ->with('status', 'Your console session expired. Please sign in again.');
        }

        $request->session()->put(self::STAMP, $now);

        return $next($request);
    }
}
