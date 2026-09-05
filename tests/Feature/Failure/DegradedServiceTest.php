<?php

declare(strict_types=1);

namespace Tests\Feature\Failure;

use App\Modules\Commission\Models\CommissionRule;
use App\Modules\Payments\Actions\PreparePayment;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsCommerceFixtures;
use Tests\Feature\Orders\BuildsPlacedOrders;
use Tests\Feature\Payments\BuildsPayableOrders;
use Tests\Support\Failure\FailsAtQuery;
use Tests\TestCase;
use Throwable;

/**
 * Services that are allowed to be away.
 *
 * Not every dependency is load-bearing. The payment provider, the SSR
 * renderer, the analytics rollups and the recommendation projections all
 * fail differently, and the useful question for each is the same: what
 * does a customer see, and did anything authoritative move?
 */
final class DegradedServiceTest extends TestCase
{
    use BuildsCommerceFixtures;
    use BuildsPayableOrders;
    use BuildsPlacedOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CommissionRule::factory()->create(['rate_percent' => '12.00']);
    }

    // ---------------------------------------------------------------
    // Payment provider.
    // ---------------------------------------------------------------

    /**
     * Preparation fails, and the order is exactly where it was.
     *
     * The provider is asked to create the intent before the customer can
     * pay. If it cannot, nothing has happened yet — and nothing is
     * allowed to look as though it has: no paid transition, no earning,
     * no commission.
     */
    #[Test]
    public function a_provider_outage_during_preparation_leaves_the_order_safe(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $order = $this->placeOrder([[$offer, 1]]);

        $this->provider()->goOffline();

        $raised = false;

        try {
            app(PreparePayment::class)($order);
        } catch (Throwable) {
            $raised = true;
        }

        $this->assertTrue($raised, 'Preparation against an offline provider appeared to succeed.');

        $order->refresh();

        $this->assertSame('pending_payment', $order->status->value);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('seller_ledger_entries', 0);
        $this->assertDatabaseCount('platform_revenue_entries', 0);
    }

    /** The hold on the stock is untouched, so the expiry policy still owns it. */
    #[Test]
    public function a_provider_outage_does_not_disturb_the_inventory_hold(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $order = $this->placeOrder([[$offer, 2]]);

        $before = DB::table('inventory_balances')->where('offer_id', $offer->id)->first();

        $this->provider()->goOffline();

        try {
            app(PreparePayment::class)($order);
        } catch (Throwable) {
            // Expected.
        }

        $after = DB::table('inventory_balances')->where('offer_id', $offer->id)->first();

        $this->assertEquals($before?->reserved, $after?->reserved);
        $this->assertEquals($before?->on_hand, $after?->on_hand);
    }

    /** And the customer can try again once the provider is back. */
    #[Test]
    public function the_customer_can_retry_once_the_provider_returns(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $order = $this->placeOrder([[$offer, 1]]);

        $provider = $this->provider();
        $provider->goOffline();

        try {
            app(PreparePayment::class)($order);
        } catch (Throwable) {
            // Expected.
        }

        $provider->comeBackOnline();

        ['reference' => $reference] = $this->prepare($order);
        $provider->settle($reference, PaymentAttemptStatus::Succeeded);
        $this->deliverEvent('payment_intent.succeeded', $reference);

        $this->assertSame('paid', $order->refresh()->status->value);
        $this->assertDatabaseCount('payments', 1);
    }

    /**
     * A customer never reads an exception message.
     *
     * Provider prose, internal enum values and class names are all things
     * an unguarded error page would print to somebody trying to buy
     * something.
     */
    #[Test]
    public function a_provider_outage_shows_the_customer_nothing_internal(): void
    {
        config(['app.debug' => false]);

        ['offer' => $offer] = $this->sellableOffer();
        $order = $this->placeOrder([[$offer, 1]]);

        $this->provider()->goOffline();

        $response = $this->get('/checkout/'.$order->reference.'/payment');

        $body = (string) $response->getContent();

        foreach (['ProviderUnavailable', 'FakePaymentProvider', 'Stripe', 'Exception', 'vendor/'] as $leak) {
            $this->assertStringNotContainsString($leak, $body, "The payment page disclosed \"{$leak}\".");
        }
    }

    // ---------------------------------------------------------------
    // Server-side rendering.
    // ---------------------------------------------------------------

    /**
     * SSR away means client-side rendering, not an error page.
     *
     * The policy is explicit: for Phase 1 the storefront degrades to CSR
     * rather than refusing to serve. A crawler gets a worse page for the
     * duration of the incident; a customer gets a working shop, which is
     * the trade worth making. The deployment gate, not the request path,
     * is where a missing SSR bundle is refused — `app:production-check`
     * fails on it.
     */
    #[Test]
    public function the_storefront_still_serves_when_the_ssr_renderer_is_away(): void
    {
        config([
            'inertia.ssr.enabled' => true,
            'inertia.ssr.url' => 'http://127.0.0.1:1',
        ]);

        Http::fake(['*' => Http::response('', 500)]);

        ['product' => $product] = $this->sellableOffer();

        $this->get('/products/'.$product->slug)->assertOk();
    }

    /** And the failure does not print the renderer's address to the page. */
    #[Test]
    public function an_ssr_failure_leaks_nothing_to_the_page(): void
    {
        config([
            'app.debug' => false,
            'inertia.ssr.enabled' => true,
            'inertia.ssr.url' => 'http://ssr.internal:13714',
        ]);

        Http::fake(['*' => Http::response('', 500)]);

        ['product' => $product] = $this->sellableOffer();

        $body = (string) $this->get('/products/'.$product->slug)->getContent();

        $this->assertStringNotContainsString('ssr.internal', $body);
        $this->assertStringNotContainsString('13714', $body);
    }

    // ---------------------------------------------------------------
    // Derived projections.
    // ---------------------------------------------------------------

    /**
     * An analytics rebuild that fails changes nothing about commerce.
     *
     * Analytics is derived from orders; orders are never derived from
     * analytics. A rollup that dies halfway must not have touched a
     * single financial row.
     */
    #[Test]
    public function a_failed_analytics_rebuild_leaves_commerce_untouched(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $order = $this->placeOrder([[$offer, 1]]);
        $this->payFor($order);

        $before = $this->commerceFingerprint();

        FailsAtQuery::containing('insert into "daily_marketplace_metrics"');

        try {
            $this->artisan('analytics:rebuild', ['--days' => 1]);
        } catch (Throwable) {
            // Expected.
        }

        $this->assertSame($before, $this->commerceFingerprint());
    }

    /** And it recovers completely on the next run. */
    #[Test]
    public function analytics_recovers_on_the_next_rebuild(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $order = $this->placeOrder([[$offer, 1]]);
        $this->payFor($order);

        FailsAtQuery::containing('insert into "daily_marketplace_metrics"');

        try {
            $this->artisan('analytics:rebuild', ['--days' => 1]);
        } catch (Throwable) {
            // Expected.
        }

        $this->runArtisan('analytics:rebuild', ['--days' => 2])->assertSuccessful()->run();

        $this->assertGreaterThan(0, DB::table('daily_marketplace_metrics')->count());
    }

    /**
     * A product page survives a broken recommendation projection.
     *
     * Recommendations are a panel on a page, not the page. With the
     * projection empty — which is what a failed rebuild leaves — the
     * product still has to be buyable.
     */
    #[Test]
    public function a_product_page_survives_an_empty_recommendation_projection(): void
    {
        ['product' => $product] = $this->sellableOffer();

        DB::table('product_associations')->delete();
        DB::table('product_popularity_scores')->delete();

        $this->get('/products/'.$product->slug)->assertOk();
    }

    /** And a failed rebuild leaves the catalogue itself alone. */
    #[Test]
    public function a_failed_recommendation_rebuild_does_not_touch_the_catalogue(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $order = $this->placeOrder([[$offer, 1]]);
        $this->payFor($order);

        $before = $this->commerceFingerprint();

        FailsAtQuery::containing('insert into "product_popularity_scores"');

        try {
            $this->artisan('recommendations:rebuild');
        } catch (Throwable) {
            // Expected.
        }

        $this->assertSame($before, $this->commerceFingerprint());
    }

    /** @return array<string, string> */
    private function commerceFingerprint(): array
    {
        $tables = [
            'marketplace_orders', 'seller_orders', 'order_items', 'payments',
            'seller_ledger_entries', 'platform_revenue_entries', 'inventory_balances',
            'products', 'offers',
        ];

        $fingerprint = [];

        foreach ($tables as $table) {
            $fingerprint[$table] = (string) DB::table($table.' as t')
                ->selectRaw('coalesce(md5(string_agg(t.*::text, \'|\' order by t.*::text)), \'empty\') as f')
                ->value('f');
        }

        return $fingerprint;
    }
}
