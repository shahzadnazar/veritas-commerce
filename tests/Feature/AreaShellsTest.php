<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Identity\Models\User;
use Database\Seeders\CommissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The three areas render, and each is reachable only by its own audience.
 */
final class AreaShellsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_storefront_is_public(): void
    {
        // The pages are client-rendered, so the assertion is on the Inertia
        // component and its props rather than on rendered copy.
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Home')
                ->has('stats.products')
                ->has('stats.sellers')
                ->where('platform.name', config('veritas.identity.display_name')));
    }

    #[Test]
    public function the_seller_portal_requires_authentication(): void
    {
        $this->get('/seller')->assertRedirect('/login');
    }

    #[Test]
    public function a_signed_in_customer_without_a_store_cannot_reach_the_seller_portal(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/seller')
            // 404, not 403: a 403 would confirm the area exists for them.
            ->assertNotFound();
    }

    #[Test]
    public function a_seller_reaches_their_own_dashboard(): void
    {
        ['user' => $user] = $this->makeSeller();

        $this->actingAs($user)->get('/seller')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->has('seller.legalName')
                ->has('seller.status')
                ->has('seller.roleLabel')
                // Onboarding state, not trading figures: there is nothing
                // to trade with yet, and inventing numbers would be worse
                // than an honest empty screen.
                ->has('setup', 4));
    }

    #[Test]
    public function the_admin_area_requires_the_admin_guard(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    #[Test]
    public function a_customer_session_does_not_open_the_admin_area(): void
    {
        // The whole point of the separate guard: a stolen or ordinary
        // customer session must be worthless against /admin.
        $this->actingAs(User::factory()->create())
            ->get('/admin')
            ->assertRedirect('/admin/login');
    }

    #[Test]
    public function an_admin_with_dashboard_permission_reaches_the_control_centre(): void
    {
        $this->seed(CommissionSeeder::class);

        // MFA is mandatory for staff, so reaching the dashboard means
        // having a confirmed second factor.
        $admin = AdminUser::factory()->role(AdminRole::SellerOperations)->withTwoFactor()->create();

        $this->actingAs($admin, 'admin')->get('/admin')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->has('queues', 4)
                ->where('commissionRate', '12.00'));
    }

    #[Test]
    public function the_admin_login_page_renders(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Login'));
    }

    #[Test]
    public function the_portals_are_marked_noindex_and_the_storefront_is_not(): void
    {
        ['user' => $user] = $this->makeSeller();

        $this->actingAs($user)->get('/seller')->assertSee('noindex, nofollow', escape: false);
        $this->get('/')->assertDontSee('noindex', escape: false);
    }

    #[Test]
    public function the_platform_name_comes_from_configuration(): void
    {
        config(['veritas.identity.display_name' => 'Configured Marketplace']);

        $this->get('/')->assertInertia(
            fn (AssertableInertia $page) => $page->where('platform.name', 'Configured Marketplace'),
        );
    }
}
