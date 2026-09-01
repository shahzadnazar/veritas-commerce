<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Response headers that hold for every surface.
 *
 * Deliberately the conservative set. A content policy that enumerates
 * script sources belongs with a nonce pipeline, which M1 does not have —
 * writing one now would either break the Vite client or be so loose it
 * meant nothing. What is here is what can be set correctly today:
 *
 *  - Framing is refused outright. A seller portal or an admin console
 *    inside someone else's iframe is a clickjacking primitive, and the
 *    storefront has no reason to be framed either.
 *  - MIME sniffing is off, so an upload that slipped through as the wrong
 *    type is not re-interpreted as script by the browser.
 *  - Referrers cross-origin carry the origin only; a store URL should not
 *    leak a customer's full path to a third party.
 *  - Camera, microphone and geolocation are denied. Nothing here asks for
 *    them, and a compromised script should not be able to either.
 *
 * HSTS is set only on an HTTPS response: sending it over plain HTTP is
 * ignored by browsers and would pin a developer's localhost to a scheme
 * it does not serve.
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = $response->headers;

        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Content-Security-Policy', "frame-ancestors 'none'");
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), interest-cohort=()');

        // The portals are never a search result. The storefront is the
        // product, so it must not carry this.
        if ($request->is('admin', 'admin/*', 'seller', 'seller/*')) {
            $headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        if ($request->secure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
