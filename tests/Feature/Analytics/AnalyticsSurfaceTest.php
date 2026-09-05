<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Modules\Analytics\Actions\RebuildDailyMetrics;
use App\Modules\Analytics\Support\AnalyticsDay;
use App\Modules\Commission\Models\CommissionRule;
use App\Modules\Identity\Enums\AdminPermission;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Identity\Models\User;
use App\Modules\Sellers\Enums\SellerRole;
use App\Modules\Sellers\Models\SellerAccount;
use App\Modules\Sellers\Models\SellerMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsCommerceFixtures;
use Tests\Feature\Orders\BuildsPlacedOrders;
use Tests\Feature\Payments\BuildsPayableOrders;
use Tests\TestCase;

/**
 * The analytics screens: who may see them, and what they may see.
 *
 * §52 is the load-bearing test here. A seller's screen must carry their
 * own figures and nothing that would tell them what a competitor sold —
 * and a seller portal that leaked a rival's volume would be a
 * competitive-intelligence product nobody agreed to sell.
 *
 * §2 is the other: every route below is a GET, and there is no write
 * action on either controller at all.
 */
final class AnalyticsSurfaceTest extends TestCase
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
    // Admin.
    // ---------------------------------------------------------------

    #[Test]
    public function an_admin_without_the_permission_cannot_open_analytics(): void
    {
        $this->asAdmin($this->makeAdmin(AdminRole::SellerOperations))
            ->get('/admin/analytics')
            ->assertForbidden();
    }

    #[Test]
    public function an_analyst_reads_every_figure_on_the_page(): void
    {
        $this->tradingDay();
        app(RebuildDailyMetrics::class)(AnalyticsDay::today());

        $this->asAdmin($this->makeAdmin(AdminRole::Analyst))
            ->get('/admin/analytics')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Analytics/Index')
                ->has('marketplace.totals')
                ->has('marketplace.series')
                ->has('marketplace.funnel.steps')
                ->has('products.topSellers')
                ->has('search.topPhrases')
                // Holds analytics.sellers.view, so the breakdown is present.
                ->has('sellers')
            );
    }

    #[Test]
    public function the_seller_breakdown_is_absent_without_its_own_permission(): void
    {
        $this->tradingDay();
        app(RebuildDailyMetrics::class)(AnalyticsDay::today());

        $this->assertFalse(
            AdminRole::CatalogModerator->can(AdminPermission::ViewSellerAnalytics),
            'This test needs a role that may read analytics but not the seller breakdown.',
        );

        $this->asAdmin($this->makeAdmin(AdminRole::CatalogModerator))
            ->get('/admin/analytics')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('marketplace.totals')
                // Null, not an empty list: a table with no rows reads as
                // "no seller traded", which is a different statement.
                ->where('sellers', null)
            );
    }

    #[Test]
    public function the_analytics_page_offers_no_way_to_change_anything(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(static fn ($route): bool => str_starts_with((string) $route->uri(), 'admin/analytics'))
            ->flatMap(static fn ($route): array => $route->methods())
            ->unique()
            ->values()
            ->all();

        sort($routes);

        $this->assertSame(['GET', 'HEAD'], $routes, '§2: analytics reads and never writes.');
    }

    #[Test]
    public function currency_is_a_filter_on_the_admin_page(): void
    {
        $this->tradingDay();
        app(RebuildDailyMetrics::class)(AnalyticsDay::today());

        $this->asAdmin($this->makeAdmin())
            ->get('/admin/analytics?currency=EUR')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('marketplace.currency', 'EUR')
                ->where('filters.currency', 'EUR')
            );
    }

    // ---------------------------------------------------------------
    // Seller. §52.
    // ---------------------------------------------------------------

    #[Test]
    public function a_seller_sees_their_own_figures_and_not_a_rivals(): void
    {
        ['seller' => $mine, 'offer' => $myOffer] = $this->sellableOffer('Mine', 10_000);
        ['offer' => $theirOffer] = $this->sellableOffer('Theirs', 20_000);

        $buyer = User::factory()->create();
        $this->payFor($this->placeOrder([[$myOffer, 1], [$theirOffer, 3]], (int) $buyer->id, $buyer->email));

        app(RebuildDailyMetrics::class)(AnalyticsDay::today());

        $member = $this->member($mine);

        $this->asUser($member)
            ->get('/seller/analytics')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $payload = $page->toArray()['props']['analytics'];

                $gross = $this->totalFor($payload, 'gross_minor');
                $this->assertSame(10_000, $gross['value'], "A seller's gross is their own lines only.");

                $titles = array_column($payload['topProducts'], 'title');
                $this->assertSame(['Mine'], $titles);
                $this->assertNotContains('Theirs', $titles);
            });
    }

    #[Test]
    public function a_seller_analytics_payload_never_names_another_seller(): void
    {
        ['seller' => $mine, 'offer' => $myOffer] = $this->sellableOffer('Mine', 10_000);
        ['seller' => $rival, 'offer' => $theirOffer] = $this->sellableOffer('Theirs', 20_000);

        $buyer = User::factory()->create();
        $this->payFor($this->placeOrder([[$myOffer, 1], [$theirOffer, 3]], (int) $buyer->id, $buyer->email));

        app(RebuildDailyMetrics::class)(AnalyticsDay::today());

        $response = $this->asUser($this->member($mine))->get('/seller/analytics');
        $response->assertOk();

        $body = $response->getContent() ?: '';

        $this->assertStringNotContainsString((string) $rival->legal_name, $body);
        $this->assertStringNotContainsString((string) $rival->public_id, $body);
        $this->assertStringNotContainsString('Theirs', $body);
    }

    #[Test]
    public function a_role_without_the_permission_cannot_open_seller_analytics(): void
    {
        ['seller' => $seller] = $this->sellableOffer();

        $this->asUser($this->member($seller, SellerRole::Viewer))
            ->get('/seller/analytics')
            ->assertForbidden();
    }

    #[Test]
    public function a_catalogue_manager_may_read_analytics_without_the_earnings(): void
    {
        ['seller' => $seller, 'offer' => $offer] = $this->sellableOffer('Listed', 10_000);

        $buyer = User::factory()->create();
        $this->payFor($this->placeOrder([[$offer, 1]], (int) $buyer->id, $buyer->email));

        app(RebuildDailyMetrics::class)(AnalyticsDay::today());

        $this->asUser($this->member($seller, SellerRole::CatalogManager))
            ->get('/seller/analytics')
            ->assertOk();

        // The same person still cannot reach the earnings statement.
        $this->asUser($this->member($seller, SellerRole::CatalogManager))
            ->get('/seller/earnings')
            ->assertForbidden();
    }

    #[Test]
    public function the_seller_analytics_page_offers_no_way_to_change_anything(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(static fn ($route): bool => (string) $route->uri() === 'seller/analytics')
            ->flatMap(static fn ($route): array => $route->methods())
            ->unique()
            ->values()
            ->all();

        sort($routes);

        $this->assertSame(['GET', 'HEAD'], $routes);
    }

    // ---------------------------------------------------------------
    // Helpers.
    // ---------------------------------------------------------------

    private function member(SellerAccount $seller, SellerRole $role = SellerRole::Owner): User
    {
        $user = User::factory()->create();

        SellerMembership::factory()->create([
            'seller_account_id' => $seller->id,
            'user_id' => $user->id,
            'role' => $role->value,
        ]);

        return $user;
    }

    private function tradingDay(): void
    {
        ['offer' => $offer] = $this->sellableOffer('Kettle', 9_900);

        $buyer = User::factory()->create();
        $this->payFor($this->placeOrder([[$offer, 1]], (int) $buyer->id, $buyer->email));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function totalFor(array $payload, string $key): array
    {
        $totals = $payload['totals'];

        $this->assertIsArray($totals);

        foreach ($totals as $total) {
            if (is_array($total) && ($total['key'] ?? null) === $key) {
                return $total;
            }
        }

        $this->fail("No total named {$key}.");
    }
}
