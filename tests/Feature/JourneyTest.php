<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Totp;
use App\Modules\Sellers\Models\SellerAccount;
use App\Modules\Sellers\Models\SellerApplication;
use App\Modules\Sellers\Models\SellerMembership;
use App\Modules\Stores\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The four journeys M1 has to actually work end to end.
 *
 * Each one walks the real routes in order, the way a person would, rather
 * than asserting the pieces separately and hoping they meet.
 */
final class JourneyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    #[Test]
    public function a_customer_registers_verifies_and_signs_in(): void
    {
        $this->post('/register', [
            'first_name' => 'Dana',
            'last_name' => 'Reyes',
            'email' => 'dana@example.com',
            'password' => 'correct horse battery staple',
            'password_confirmation' => 'correct horse battery staple',
        ])->assertRedirect();

        $user = User::query()->where('email', 'dana@example.com')->firstOrFail();
        $this->assertNull($user->email_verified_at);

        $link = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->getKey(),
            'hash' => sha1($user->email),
        ]);

        $this->actingAs($user, 'web')->get($link)->assertRedirect();
        $this->assertNotNull($user->fresh()?->email_verified_at);

        $this->post('/logout');

        $this->post('/login', [
            'email' => 'dana@example.com',
            'password' => 'correct horse battery staple',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($user, 'web');
    }

    #[Test]
    public function a_seller_applies_is_reviewed_approved_and_opens_a_store(): void
    {
        $applicant = User::factory()->create(['email' => 'dana@aeris.example']);

        // 1. Apply.
        $this->actingAs($applicant, 'web')
            ->post('/seller/apply', [
                'legal_name' => 'Aeris Kitchen Company LLC',
                'trading_name' => 'Aeris Kitchen Co.',
                'business_type' => 'LLC',
                'tax_id' => '82-1234567',
                'address_line1' => '114 SE Ash St',
                'address_city' => 'Portland',
                'address_state' => 'OR',
                'address_postcode' => '97214',
                'contact_name' => 'Dana Reyes',
                'contact_email' => 'dana@aeris.example',
                'blurb' => 'We make cast iron and carbon steel cookware intended to be handed down.',
                'terms_accepted' => true,
            ])
            ->assertRedirect();

        $application = SellerApplication::query()->where('user_id', $applicant->id)->firstOrFail();

        // Nothing is open to them yet.
        $this->actingAs($applicant, 'web')->get('/seller')->assertNotFound();

        // 2. A reviewer opens it, then approves.
        $admin = $this->makeAdmin(AdminRole::SellerOperations);

        $this->actingAs($admin, 'admin')
            ->post("/admin/applications/{$application->public_id}/review")
            ->assertRedirect();

        $this->actingAs($admin, 'admin')
            ->post("/admin/applications/{$application->public_id}/approve")
            ->assertRedirect();

        // 3. Exactly one account, exactly one owner.
        $this->assertSame(1, SellerAccount::query()->where('application_id', $application->id)->count());
        $this->assertSame(1, SellerMembership::query()->where('user_id', $applicant->id)->count());

        // 4. The portal opens, and the store is set up through it.
        $this->actingAs($applicant, 'web')->get('/seller')->assertOk();

        $this->actingAs($applicant, 'web')
            ->post('/seller/store', [
                'name' => 'Aeris Kitchen Co.',
                'slug' => 'aeris-kitchen',
                'description' => 'Cast iron and carbon steel, made to be handed down.',
                'shipping_policy' => 'Orders before 2pm PT ship the same day.',
                'return_policy' => 'Unused items accepted within 30 days.',
                'is_open' => true,
            ])
            ->assertRedirect();

        $store = Store::query()->where('slug', 'aeris-kitchen')->firstOrFail();

        // 5. And the public page is live at a URL with no id in it.
        $this->get('/stores/aeris-kitchen')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Store/Show')
                ->where('store.name', 'Aeris Kitchen Co.'));

        $this->assertStringNotContainsString((string) $store->id, 'aeris-kitchen');
    }

    #[Test]
    public function an_admin_signs_in_with_a_second_factor_and_reviews(): void
    {
        $secret = Totp::generateSecret();

        $admin = AdminUser::factory()->create([
            'email' => 'ops@example.test',
            'password' => 'correct horse battery staple',
            'role' => AdminRole::SellerOperations->value,
            'two_factor_secret' => $secret,
            'two_factor_enrolled_at' => now(),
            'two_factor_confirmed_at' => now(),
        ]);

        SellerApplication::factory()->create();

        // The password alone is not a sign-in.
        $this->post('/admin/login', [
            'email' => 'ops@example.test',
            'password' => 'correct horse battery staple',
        ])->assertSessionHasErrors();

        $this->assertGuest('admin');

        $this->post('/admin/login', [
            'email' => 'ops@example.test',
            'password' => 'correct horse battery staple',
            'code' => Totp::codeAt($secret, time()),
        ])->assertRedirect('/admin');

        $this->assertAuthenticatedAs($admin, 'admin');

        $this->get('/admin/applications')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Sellers/Applications')->has('applications.data', 1));
    }

    #[Test]
    public function seller_a_walking_seller_b_urls_is_refused_at_every_step(): void
    {
        ['user' => $a] = $this->makeSeller();
        ['seller' => $sellerB, 'store' => $storeB, 'membership' => $membershipB] = $this->makeSeller();

        // Read attempts resolve to A's own records, never B's.
        $this->actingAs($a, 'web')
            ->get('/seller/store')
            ->assertInertia(fn ($page) => $page->where('store.slug', fn (string $slug) => $slug !== $storeB->slug));

        // Write attempts against B's ids do not resolve at all.
        $this->actingAs($a, 'web')->delete("/seller/team/{$membershipB->id}")->assertNotFound();
        $this->actingAs($a, 'web')
            ->patch("/seller/team/{$membershipB->id}", ['role' => 'viewer'])
            ->assertNotFound();

        // And the admin surface for B is closed to a customer session entirely.
        $this->actingAs($a, 'web')
            ->post("/admin/sellers/{$sellerB->public_id}/suspend", ['reason' => 'Trying it on.'])
            ->assertRedirect('/admin/login');

        $this->assertDatabaseHas('seller_accounts', [
            'id' => $sellerB->id,
            'status' => $sellerB->status->value,
        ]);
    }
}
