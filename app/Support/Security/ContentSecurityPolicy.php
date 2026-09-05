<?php

declare(strict_types=1);

namespace App\Support\Security;

use Illuminate\Http\Request;

/**
 * The content policy, built from what this application actually loads.
 *
 * It was written after auditing the rendered HTML of every surface rather
 * than from a template, and the audit is what made a strict policy
 * possible. What the pages contain is:
 *
 *  - one same-origin module script per portal, emitted by Vite;
 *  - one same-origin stylesheet, plus the webfont stylesheet and font
 *    files the three layouts load from Bunny Fonts;
 *  - `<script data-page="app" type="application/json">`, which is Inertia's
 *    prop payload — a data block, not executable, and not governed by
 *    script-src;
 *  - `<script type="application/ld+json">` on product pages, likewise a
 *    data block, so structured data survives this policy untouched;
 *  - no inline executable script anywhere, no `<style>` element, no
 *    `data:` URI, no blob, no worker.
 *
 * So `script-src 'self'` holds with no nonce pipeline and no hashes, which
 * is the outcome worth having: a policy that would have needed
 * `unsafe-inline` to pass is a policy that permits what it claims to stop.
 *
 * THE ONE RELAXATION, and why it is the small one:
 *
 * React writes inline `style` attributes for values only known at runtime
 * — a bar chart's height, a tree row's indent, a rating bar's width.
 * Those are style ATTRIBUTES, which cannot execute script; the risk they
 * carry is defacement and CSS-based exfiltration, not code execution. So
 * the relaxation is scoped as narrowly as CSP allows: browsers that
 * understand the granular directives get `style-src-elem 'self'` — no
 * injected `<style>` element, no foreign stylesheet — with
 * `'unsafe-inline'` confined to `style-src-attr`. `style-src` carries both
 * only as the fallback for browsers that do not support the split.
 *
 * Stripe's origins are added on the payment page and nowhere else. The
 * storefront has no business being able to load js.stripe.com, and a
 * policy that lets every page do it because one page must is a policy
 * that has been widened to the least secure surface.
 */
final class ContentSecurityPolicy
{
    /**
     * Stripe's documented requirements for Elements, and only those.
     *
     * js.stripe.com serves the library and hosts the card iframe;
     * hooks.stripe.com hosts the 3-D Secure challenge; api.stripe.com is
     * what the library calls. Stripe's fraud signals load inside the
     * js.stripe.com frame and are governed by that frame's own policy, not
     * by ours, so `m.stripe.network` is deliberately not listed here.
     */
    public const STRIPE_SCRIPT = 'https://js.stripe.com';

    public const STRIPE_FRAMES = ['https://js.stripe.com', 'https://hooks.stripe.com'];

    public const STRIPE_CONNECT = 'https://api.stripe.com';

    /** The paths that render Stripe Elements. */
    public const PAYMENT_PATHS = ['checkout/*/payment', 'checkout/*/payment/*'];

    /**
     * The webfont host the three layouts load from.
     *
     * Bunny serves both the stylesheet and the font files from one origin,
     * so it appears in style-src and font-src rather than the two-host
     * split Google Fonts needs. `LayoutOriginsAreInThePolicyTest` parses
     * the layouts and fails if they ever reference an origin this policy
     * does not name — which is how this entry came to exist, having been
     * missed on the first pass.
     */
    public const FONT_ORIGIN = 'https://fonts.bunny.net';

    public static function forRequest(Request $request): string
    {
        return self::render(self::directives($request->is(...self::PAYMENT_PATHS)));
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function directives(bool $withPayments): array
    {
        $script = ["'self'"];
        $connect = ["'self'"];
        $frame = ["'none'"];

        if ($withPayments) {
            $script[] = self::STRIPE_SCRIPT;
            $connect[] = self::STRIPE_CONNECT;
            $frame = self::STRIPE_FRAMES;
        }

        $image = ["'self'"];

        foreach (self::mediaOrigins() as $origin) {
            $image[] = $origin;
        }

        return [
            // Everything falls back to same-origin, so a directive this
            // policy forgets fails closed rather than open.
            'default-src' => ["'self'"],
            'base-uri' => ["'self'"],
            'object-src' => ["'none'"],
            'form-action' => ["'self'"],
            // Unchanged from M1, and the reason is unchanged: a seller
            // portal or an admin console inside somebody's iframe is a
            // clickjacking primitive.
            'frame-ancestors' => ["'none'"],
            'script-src' => $script,
            'style-src' => ["'self'", "'unsafe-inline'", self::FONT_ORIGIN],
            'style-src-elem' => ["'self'", self::FONT_ORIGIN],
            'style-src-attr' => ["'unsafe-inline'"],
            'img-src' => $image,
            'font-src' => ["'self'", self::FONT_ORIGIN],
            'connect-src' => $connect,
            'frame-src' => $frame,
        ];
    }

    /**
     * The origin product imagery is served from, when it is not our own.
     *
     * Read through config so it survives `config:cache` — an origin
     * resolved from `env()` at request time would be absent on exactly
     * the deployments that cache their configuration, and every product
     * image on the site would vanish behind the policy.
     *
     * @return array<int, string>
     */
    private static function mediaOrigins(): array
    {
        $origins = [];

        foreach ([
            config('filesystems.disks.media.url'),
            config('veritas.media.url'),
        ] as $configured) {
            if (! is_string($configured) || $configured === '') {
                continue;
            }

            $host = parse_url($configured, PHP_URL_HOST);
            $scheme = parse_url($configured, PHP_URL_SCHEME);

            if (! is_string($host) || $host === '') {
                continue;
            }

            $origin = (is_string($scheme) && $scheme !== '' ? $scheme : 'https').'://'.$host;

            // Same-origin media is already covered by 'self'; listing it
            // again would only make the header longer.
            if ($origin === rtrim((string) config('app.url'), '/')) {
                continue;
            }

            $origins[$origin] = $origin;
        }

        return array_values($origins);
    }

    /** @param array<string, array<int, string>> $directives */
    private static function render(array $directives): string
    {
        $parts = [];

        foreach ($directives as $name => $sources) {
            $parts[] = $name.' '.implode(' ', $sources);
        }

        return implode('; ', $parts);
    }
}
