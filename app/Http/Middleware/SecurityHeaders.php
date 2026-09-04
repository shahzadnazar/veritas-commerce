<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\Catalog\Support\Indexability;
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
    /**
     * The policy, one directive at a time.
     *
     * `frame-src` is here because of Stripe, and it is a tightening rather
     * than a loosening: with no directive at all a page could frame
     * anything, and enumerating the two Stripe origins Elements actually
     * uses — js.stripe.com for the card fields, hooks.stripe.com for the
     * 3-D Secure challenge — closes that to everything else. Naming them
     * is the whole point; a wildcard, or `unsafe-*` anywhere, would buy the
     * integration at the cost of the policy meaning anything (§59).
     *
     * `script-src` is still deliberately absent, for the reason the class
     * docblock gives: enumerating script sources needs a nonce pipeline
     * this application does not have, and a `script-src` loose enough to
     * pass Vite would be a directive that permits what it claims to stop.
     */
    private const CONTENT_POLICY = [
        "frame-ancestors 'none'",
        "frame-src 'self' https://js.stripe.com https://hooks.stripe.com",
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = $response->headers;

        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Content-Security-Policy', implode('; ', self::CONTENT_POLICY));
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), interest-cohort=()');

        // The portals are never a search result. The storefront is the
        // product, so it must not carry this.
        if ($request->is('admin', 'admin/*', 'seller', 'seller/*')) {
            $headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        /*
         * Search results, as a header rather than only a meta tag.
         *
         * The `<meta name="robots">` a page renders only reaches a crawler
         * when SSR is on. A header reaches it either way, and is honoured
         * for non-HTML responses too — so the noindex on search survives a
         * misconfigured SSR process, which is exactly when an accidental
         * index of the whole search space would happen.
         */
        if ($request->is('search', 'search/*')) {
            $headers->set('X-Robots-Tag', Indexability::NOINDEX);
        }

        /*
         * Carts, checkouts and account pages, for the same reason and
         * more urgently: these carry an address, an order total and a
         * purchase history. A header reaches a crawler even when SSR is
         * misconfigured, which is precisely the moment an accidental
         * index would happen.
         */
        if ($request->is(...Indexability::privatePaths())) {
            $headers->set('X-Robots-Tag', Indexability::PRIVATE);
        }

        if ($request->secure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
