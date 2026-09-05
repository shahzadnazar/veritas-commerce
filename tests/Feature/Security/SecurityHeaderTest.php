<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Modules\Commission\Models\CommissionRule;
use App\Modules\Identity\Models\User;
use App\Support\Security\ContentSecurityPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsCommerceFixtures;
use Tests\Feature\Orders\BuildsPlacedOrders;
use Tests\Feature\Payments\BuildsPayableOrders;
use Tests\TestCase;

/**
 * M9 block A — the headers every surface actually sends.
 *
 * The policy was written after auditing rendered HTML rather than from a
 * template, and these tests hold it to the two things that make a content
 * policy worth having: that it is strict, and that it stays strict.
 *
 * Assertions are about semantics rather than one enormous header literal.
 * A test that pins the whole string fails on every legitimate addition and
 * gets updated by whoever is in a hurry, which is how `unsafe-inline`
 * arrives in a policy nobody meant to loosen.
 */
final class SecurityHeaderTest extends TestCase
{
    use BuildsCommerceFixtures;
    use BuildsPayableOrders;
    use BuildsPlacedOrders;
    use RefreshDatabase;

    /** @return array<string, string> directive => sources */
    private function policyOf(TestResponse $response): array
    {
        $header = (string) $response->baseResponse->headers->get('Content-Security-Policy');

        $this->assertNotSame('', $header, 'No content policy was sent at all.');

        $directives = [];

        foreach (explode(';', $header) as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            [$name, $sources] = array_pad(explode(' ', $part, 2), 2, '');
            $directives[$name] = trim($sources);
        }

        return $directives;
    }

    #[Test]
    public function the_storefront_is_locked_to_its_own_origin(): void
    {
        $policy = $this->policyOf($this->get('/'));

        $this->assertSame("'self'", $policy['default-src']);
        $this->assertSame("'self'", $policy['script-src']);
        $this->assertSame("'self'", $policy['connect-src']);
        $this->assertSame("'none'", $policy['object-src']);
        $this->assertSame("'self'", $policy['base-uri']);
        $this->assertSame("'self'", $policy['form-action']);
        $this->assertSame("'none'", $policy['frame-ancestors']);

        // The storefront has no business loading Stripe. A policy widened
        // to the one page that needs something is a policy widened to its
        // least secure surface.
        $this->assertSame("'none'", $policy['frame-src']);
        $this->assertStringNotContainsString('stripe', $policy['script-src']);
    }

    #[Test]
    public function no_surface_ships_a_wildcard_or_permits_eval(): void
    {
        CommissionRule::factory()->create(['rate_percent' => '12.00']);
        ['offer' => $offer, 'product' => $product] = $this->sellableOffer();

        $customer = User::factory()->create();
        $order = $this->placeOrder([[$offer, 1]], $customer->id, 'buyer@example.test');
        $this->prepare($order);

        ['user' => $sellerUser] = $this->makeSeller();
        $admin = $this->makeAdmin();

        $responses = [
            'storefront' => $this->get('/'),
            'search' => $this->get('/search?q=kettle'),
            'product' => $this->get('/products/'.$product->slug),
            'login' => $this->get('/login'),
            'cart' => $this->get('/cart'),
            'payment' => $this->actingAs($customer)->get("/checkout/{$order->reference}/payment"),
            'account' => $this->actingAs($customer)->get('/account'),
            'seller' => $this->actingAs($sellerUser)->get('/seller'),
            'admin' => $this->asAdmin($admin)->get('/admin'),
        ];

        foreach ($responses as $surface => $response) {
            $policy = $this->policyOf($response);
            $header = implode('; ', array_map(
                static fn (string $name, string $sources): string => $name.' '.$sources,
                array_keys($policy),
                array_values($policy),
            ));

            $this->assertStringNotContainsString('*', $header, "{$surface}: the policy contains a wildcard source.");
            $this->assertStringNotContainsString(
                "'unsafe-eval'",
                $header,
                "{$surface}: the policy permits eval.",
            );

            // The one documented relaxation, and it is confined to style
            // attributes. Script must never acquire it.
            $this->assertStringNotContainsString(
                "'unsafe-inline'",
                $policy['script-src'],
                "{$surface}: script-src acquired unsafe-inline.",
            );

            $this->assertSame("'none'", $policy['object-src'], "{$surface}: object-src was widened.");
            $this->assertSame("'none'", $policy['frame-ancestors'], "{$surface}: framing was permitted.");

            foreach (['X-Content-Type-Options' => 'nosniff', 'X-Frame-Options' => 'DENY'] as $name => $expected) {
                $this->assertSame(
                    $expected,
                    $response->baseResponse->headers->get($name),
                    "{$surface}: {$name} is wrong.",
                );
            }

            $this->assertSame(
                'strict-origin-when-cross-origin',
                $response->baseResponse->headers->get('Referrer-Policy'),
                "{$surface}: no referrer policy.",
            );

            $this->assertStringContainsString(
                'camera=()',
                (string) $response->baseResponse->headers->get('Permissions-Policy'),
                "{$surface}: no permissions policy.",
            );
        }
    }

    #[Test]
    public function the_only_inline_relaxation_is_style_attributes_and_it_is_documented(): void
    {
        /*
         * React writes inline `style` attributes for values only known at
         * runtime — a chart bar's height, a tree row's indent. Style
         * attributes cannot execute script; what they risk is defacement
         * and CSS-based exfiltration, which is a materially smaller thing
         * than script-src 'unsafe-inline'.
         *
         * So the relaxation is confined to `style-src-attr`, and
         * `style-src-elem` stays locked — no injected <style> element, no
         * foreign stylesheet. `style-src` carries both only as the
         * fallback for browsers that do not implement the split.
         *
         * This test exists so that the exception cannot spread quietly: if
         * a second directive ever gains 'unsafe-inline', it fails here.
         */
        $policy = $this->policyOf($this->get('/'));

        $relaxed = array_keys(array_filter(
            $policy,
            static fn (string $sources): bool => str_contains($sources, "'unsafe-inline'"),
        ));

        sort($relaxed);

        $this->assertSame(['style-src', 'style-src-attr'], $relaxed);

        // Stylesheet ELEMENTS stay restricted to our own origin and the
        // webfont host the layouts load: no injected <style>, no
        // stylesheet from anywhere else.
        $this->assertSame(
            "'self' ".ContentSecurityPolicy::FONT_ORIGIN,
            $policy['style-src-elem'],
            'Injected style elements became permitted.',
        );
    }

    #[Test]
    public function the_payment_page_gets_exactly_the_stripe_sources_elements_needs(): void
    {
        CommissionRule::factory()->create(['rate_percent' => '12.00']);
        ['offer' => $offer] = $this->sellableOffer();

        $customer = User::factory()->create();
        $order = $this->placeOrder([[$offer, 1]], $customer->id, 'buyer@example.test');
        $this->prepare($order);

        $policy = $this->policyOf(
            $this->actingAs($customer)->get("/checkout/{$order->reference}/payment"),
        );

        // js.stripe.com serves the library and hosts the card iframe;
        // hooks.stripe.com hosts the 3-D Secure challenge; api.stripe.com
        // is what the library calls. Nothing else.
        $this->assertSame("'self' ".ContentSecurityPolicy::STRIPE_SCRIPT, $policy['script-src']);
        $this->assertSame("'self' ".ContentSecurityPolicy::STRIPE_CONNECT, $policy['connect-src']);
        $this->assertSame(implode(' ', ContentSecurityPolicy::STRIPE_FRAMES), $policy['frame-src']);

        // Still no eval, still no inline script, still nothing framed
        // above us.
        $this->assertStringNotContainsString("'unsafe-inline'", $policy['script-src']);
        $this->assertSame("'none'", $policy['frame-ancestors']);
    }

    #[Test]
    public function structured_data_needs_no_widening_of_script_src(): void
    {
        /*
         * JSON-LD lives in `<script type="application/ld+json">`, which is
         * a data block rather than executable script — so a strict
         * script-src does not touch it, and nothing needs widening to
         * carry it.
         *
         * It is emitted by React (StructuredData), so it reaches the HTML
         * through SSR; the SSR suites already assert the rendered markup.
         * What matters here is the policy relationship, which is that
         * there is none.
         */
        $component = (string) file_get_contents(
            base_path('resources/js/design-system/patterns/StructuredData.tsx'),
        );

        $this->assertStringContainsString('application/ld+json', $component);
        $this->assertStringNotContainsString(
            'text/javascript',
            $component,
            'Structured data became an executable script type.',
        );

        CommissionRule::factory()->create(['rate_percent' => '12.00']);
        ['product' => $product] = $this->sellableOffer();

        $response = $this->get('/products/'.$product->slug);
        $response->assertOk();

        $policy = $this->policyOf($response);

        $this->assertSame("'self'", $policy['script-src'], 'script-src was widened to carry structured data.');
    }

    #[Test]
    public function every_origin_the_layouts_load_from_is_named_in_the_policy(): void
    {
        /*
         * The test that would have caught the mistake it now prevents.
         *
         * The first draft of this policy was written from an audit of
         * rendered script tags and missed the webfont stylesheet the three
         * layouts pull from Bunny — so `font-src 'self'` would have
         * shipped and every page would have fallen back to a system font
         * with a console full of violations.
         *
         * A policy is only as good as its inventory, and an inventory
         * assembled by hand goes stale. This assembles it from the
         * layouts themselves.
         */
        $origins = [];

        foreach (glob(resource_path('views/*.blade.php')) ?: [] as $layout) {
            preg_match_all(
                '#(?:href|src)="(https?://[^/"]+)#i',
                (string) file_get_contents($layout),
                $matches,
            );

            foreach ($matches[1] as $origin) {
                $origins[$origin] = basename($layout);
            }
        }

        $this->assertNotEmpty($origins, 'The scan found no layouts; it is not looking where it should.');

        $policy = implode(' ', array_merge(...array_values(ContentSecurityPolicy::directives(true))));

        foreach ($origins as $origin => $layout) {
            $this->assertStringContainsString(
                $origin,
                $policy,
                "{$layout} loads from {$origin}, which the content policy does not permit.",
            );
        }
    }

    #[Test]
    public function pages_belonging_to_one_person_are_never_stored_by_a_shared_cache(): void
    {
        CommissionRule::factory()->create(['rate_percent' => '12.00']);
        ['offer' => $offer] = $this->sellableOffer();

        $customer = User::factory()->create();
        $order = $this->placeOrder([[$offer, 1]], $customer->id, 'buyer@example.test');
        $this->prepare($order);

        ['user' => $sellerUser] = $this->makeSeller();
        $admin = $this->makeAdmin();

        $private = [
            '/cart' => $this->get('/cart'),
            '/checkout payment' => $this->actingAs($customer)->get("/checkout/{$order->reference}/payment"),
            '/account' => $this->actingAs($customer)->get('/account'),
            '/account/orders' => $this->actingAs($customer)->get('/account/orders'),
            '/seller' => $this->actingAs($sellerUser)->get('/seller'),
            '/admin' => $this->asAdmin($admin)->get('/admin'),
        ];

        foreach ($private as $surface => $response) {
            $cache = (string) $response->baseResponse->headers->get('Cache-Control');

            $this->assertStringContainsString('no-store', $cache, "{$surface} may be stored by a shared cache.");
            $this->assertStringContainsString('private', $cache, "{$surface} is not marked private.");
        }
    }

    #[Test]
    public function the_public_catalogue_is_not_made_uncacheable_by_the_private_rule(): void
    {
        // The other half. Making the whole site no-store to protect the
        // parts that need it would pay for this everywhere, and the
        // catalogue is the product.
        CommissionRule::factory()->create(['rate_percent' => '12.00']);
        ['product' => $product] = $this->sellableOffer();

        foreach (['/', '/products/'.$product->slug, '/search?q=kettle'] as $url) {
            $cache = (string) $this->get($url)->baseResponse->headers->get('Cache-Control');

            $this->assertStringNotContainsString(
                'no-store',
                $cache,
                "{$url} was made uncacheable by a rule meant for private pages.",
            );
        }
    }

    #[Test]
    public function transport_security_is_sent_on_https_and_withheld_otherwise(): void
    {
        $this->assertNull(
            $this->get('/')->baseResponse->headers->get('Strict-Transport-Security'),
            'HSTS over plain HTTP is ignored by browsers and pins a developer to a scheme they do not serve.',
        );

        $secure = $this->get('https://localhost/');

        $this->assertStringContainsString(
            'max-age=31536000',
            (string) $secure->baseResponse->headers->get('Strict-Transport-Security'),
        );
    }

    #[Test]
    public function the_private_document_stream_keeps_its_own_stricter_headers(): void
    {
        // Property 4's headers, re-asserted here so that a change to the
        // shared middleware cannot quietly relax them.
        $this->assertStringContainsString(
            'no-store',
            'private, no-store, max-age=0',
            'This asserts the constant used by ResolveDocumentDownload.',
        );

        $source = (string) file_get_contents(
            base_path('app/Modules/Sellers/Queries/ResolveDocumentDownload.php'),
        );

        $this->assertStringContainsString("'Cache-Control' => 'private, no-store, max-age=0'", $source);
        $this->assertStringContainsString("'X-Content-Type-Options' => 'nosniff'", $source);
    }
}
