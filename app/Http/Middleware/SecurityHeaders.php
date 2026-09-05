<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\Catalog\Support\Indexability;
use App\Support\Security\ContentSecurityPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Response headers that hold for every surface.
 *
 * The content policy is built by ContentSecurityPolicy from what the
 * application actually loads — audited from rendered HTML rather than
 * written from a template — and it is strict: same-origin scripts, no
 * inline execution, nothing framed, and Stripe's origins only on the page
 * that renders Stripe.
 *
 * The rest of the set:
 *
 *  - Framing is refused outright, in the policy and in the legacy header
 *    for browsers that predate it.
 *  - MIME sniffing is off, so an upload that slipped through as the wrong
 *    type is not re-interpreted as script by the browser.
 *  - Referrers cross-origin carry the origin only; a store URL should not
 *    leak a customer's full path to a third party.
 *  - Camera, microphone and geolocation are denied. Nothing here asks for
 *    them, and a compromised script should not be able to either.
 *  - Anything holding one person's data is marked no-store, so a shared
 *    cache or a CDN in front of the application cannot keep a copy of
 *    somebody's address, order total or paperwork.
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
        $headers->set('Content-Security-Policy', ContentSecurityPolicy::forRequest($request));
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

        $this->refuseSharedCaching($request, $response);

        return $response;
    }

    /**
     * Nothing belonging to one person is stored by a shared cache.
     *
     * Laravel's session middleware already sends `no-cache, private`,
     * which requires revalidation but still permits a copy to be written
     * to disk. For a cart, an order, a portal screen or a KYC document
     * that is the wrong trade: `no-store` says do not keep it at all,
     * which is what a CDN sitting in front of this application has to be
     * told before it caches somebody's address.
     *
     * Deliberately scoped rather than global. The catalogue is the
     * product, it is identical for everybody, and making the whole site
     * uncacheable to protect the parts that need it would be paying for
     * this everywhere.
     */
    private function refuseSharedCaching(Request $request, Response $response): void
    {
        $private = $request->is(
            ...Indexability::privatePaths(),
            ...['admin', 'admin/*', 'seller', 'seller/*'],
        );

        if (! $private) {
            return;
        }

        $response->headers->set('Cache-Control', 'no-store, no-cache, private, max-age=0');
    }
}
