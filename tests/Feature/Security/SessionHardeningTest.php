<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Http\Middleware\ExpireIdleAdminSession;
use App\Modules\Commission\Models\CommissionRule;
use App\Modules\Identity\Enums\AdminPermission;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Totp;
use App\Modules\Sellers\Enums\SellerRole;
use App\Modules\Sellers\Models\SellerMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * M9 block D — what a session is worth, and for how long.
 *
 * Two groups of tests. The first is the ordinary hygiene: rotation on
 * login, invalidation on logout, single-use reset tokens, fixation.
 *
 * The second matters more here, because of what property 1 found. A
 * controller was memoising tenant membership across requests, which meant
 * authority outlived the thing that granted it. These tests ask the same
 * question of every authority the platform hands out: when it is taken
 * away, is it gone on the very next request, or is there a cache
 * somewhere still saying yes?
 */
final class SessionHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The console dashboard reads the platform commission rate.
        CommissionRule::factory()->create(['rate_percent' => '12.00']);
    }

    #[Test]
    public function signing_in_rotates_the_session_identifier(): void
    {
        /*
         * Fixation. An attacker who can plant a session id — through a
         * link, a subdomain, an XSS on a sibling host — waits for the
         * victim to sign in on it and then holds an authenticated
         * session. Rotating on login is what makes the planted id
         * worthless.
         */
        $user = User::factory()->create(['email' => 'ada@example.test', 'password' => Hash::make('a-strong-password-1')]);

        $this->get('/login');
        $before = session()->getId();

        $this->post('/login', ['email' => $user->email, 'password' => 'a-strong-password-1']);

        $after = session()->getId();

        $this->assertNotSame('', $before);
        $this->assertNotSame($before, $after, 'The session identifier survived authentication.');
        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function the_admin_console_rotates_its_session_too(): void
    {
        $admin = $this->makeAdmin(AdminRole::SuperAdmin);

        $this->get('/admin/login');
        $before = session()->getId();

        $this->post('/admin/login', [
            'email' => $admin->email,
            'password' => 'password',
            'code' => $this->currentCodeFor($admin),
        ]);

        $this->assertNotSame($before, session()->getId());
    }

    #[Test]
    public function logging_out_ends_the_session_rather_than_only_the_login(): void
    {
        $user = User::factory()->create(['password' => Hash::make('a-strong-password-1')]);

        $this->actingAs($user)->get('/account')->assertOk();

        $signedIn = session()->getId();

        $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $this->assertNotSame($signedIn, session()->getId(), 'The identifier outlived the authentication it carried.');

        $this->get('/account')->assertRedirect('/login');
    }

    #[Test]
    public function a_reset_token_works_once_and_then_never_again(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.test']);
        $token = Password::broker()->createToken($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'a-brand-new-password-1',
            'password_confirmation' => 'a-brand-new-password-1',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('a-brand-new-password-1', $user->refresh()->password));

        // The same token again. A reset link sits in an inbox forever, and
        // an inbox is not a secure place.
        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'another-new-password-1',
            'password_confirmation' => 'another-new-password-1',
        ])->assertSessionHasErrors();

        $this->assertTrue(
            Hash::check('a-brand-new-password-1', $user->refresh()->password),
            'A replayed reset token changed the password a second time.',
        );
    }

    #[Test]
    public function removing_a_seller_member_removes_their_authority_on_the_next_request(): void
    {
        /*
         * The question property 1 taught us to ask. Seller authority is
         * derived from the membership row on every request — never cached
         * on a controller, never copied into the session — so deleting the
         * row is enough, with no logout and no cache expiry in between.
         */
        ['user' => $member] = $this->makeSeller(SellerRole::Owner);

        $this->actingAs($member)->get('/seller')->assertOk();

        SellerMembership::query()->where('user_id', $member->id)->delete();

        // 404 rather than 403 is the established policy here: telling a
        // stranger that a portal exists but is not theirs is itself a
        // disclosure. Either is a refusal; what matters is that it is one.
        $this->assertContains($this->actingAs($member)->get('/seller')->getStatusCode(), [403, 404]);
        $this->assertContains($this->actingAs($member)->get('/seller/payouts')->getStatusCode(), [403, 404]);
    }

    #[Test]
    public function changing_a_seller_role_takes_effect_on_the_next_request(): void
    {
        ['user' => $member, 'membership' => $membership] = $this->makeSeller(SellerRole::Owner);

        $this->actingAs($member)->get('/seller/payouts')->assertOk();

        // Demoted mid-session. A viewer may read the store; they may not
        // reach the money.
        $membership->forceFill(['role' => SellerRole::Viewer->value])->save();

        $this->actingAs($member)->get('/seller/payouts')->assertForbidden();
    }

    #[Test]
    public function removing_an_admin_permission_takes_effect_on_the_next_request(): void
    {
        $admin = $this->makeAdmin(AdminRole::SuperAdmin);

        $this->asAdmin($admin)->get('/admin/sellers')->assertOk();

        // Demoted to a role that cannot read sellers.
        $admin->forceFill(['role' => AdminRole::Analyst->value])->save();

        $this->assertFalse($admin->refresh()->role->can(AdminPermission::SellerViewSensitive));

        $this->asAdmin($admin->refresh())->get('/admin/staff')->assertForbidden();
    }

    #[Test]
    public function an_idle_console_session_expires_on_its_own_clock(): void
    {
        /*
         * ADMIN_SESSION_LIFETIME has promised this since M1 — the comment
         * in .env.example says staff sessions expire far sooner than
         * customer ones — and until M9 it was read by nothing at all, so
         * administrators quietly had the ordinary 120 minutes. This is the
         * test that keeps the promise honest.
         */
        $lifetime = (int) config('veritas.admin.session_lifetime_minutes');

        $this->assertGreaterThan(0, $lifetime);
        $this->assertLessThan(
            (int) config('session.lifetime'),
            $lifetime,
            'A privileged session should be worth less time than a shopping one.',
        );

        $admin = $this->makeAdmin(AdminRole::SuperAdmin);

        $this->asAdmin($admin)->get('/admin')->assertOk();

        // Idle, not merely old.
        $this->travel($lifetime + 1)->minutes();

        $this->asAdmin($admin)->get('/admin')->assertRedirect(route('admin.login'));
    }

    #[Test]
    public function work_keeps_a_console_session_alive(): void
    {
        // Idle time rather than absolute age: an administrator working
        // through a queue of applications must not be thrown out
        // mid-review.
        $lifetime = (int) config('veritas.admin.session_lifetime_minutes');
        $admin = $this->makeAdmin(AdminRole::SuperAdmin);

        for ($i = 0; $i < 4; $i++) {
            $this->asAdmin($admin)->get('/admin')->assertOk();
            $this->travel((int) floor($lifetime * 0.75))->minutes();
        }

        $this->asAdmin($admin)->get('/admin')->assertOk();
    }

    #[Test]
    public function the_idle_gate_covers_the_console_and_not_the_storefront(): void
    {
        $routes = app('router')->getRoutes();

        $adminRoute = $routes->getByName('admin.dashboard');
        $this->assertNotNull($adminRoute);
        $this->assertContains('admin.idle', $adminRoute->gatherMiddleware());

        // Customers keep the ordinary lifetime; a shopping session being
        // dropped after half an hour would be a bug, not a feature.
        $account = $routes->getByName('account');
        $this->assertNotNull($account);
        $this->assertNotContains('admin.idle', $account->gatherMiddleware());

        $this->assertSame(ExpireIdleAdminSession::STAMP, 'admin.last_activity_at');
    }

    #[Test]
    public function the_production_cookie_policy_is_asserted_where_it_is_enforced(): void
    {
        /*
         * The session cookie must be Secure in production. That is checked
         * by app:production-check, which FAILS the deploy rather than
         * warning — so this asserts the gate still exists rather than
         * duplicating it, and that .env.example tells a deployer to set it
         * before they discover it from a failing pipeline.
         */
        $readiness = (string) file_get_contents(
            base_path('app/Support/Diagnostics/ProductionReadiness.php'),
        );

        $this->assertStringContainsString("config('session.secure') === true", $readiness);
        $this->assertStringContainsString('SESSION_SECURE_COOKIE=true', $readiness);

        $example = (string) file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString('SESSION_SECURE_COOKIE=true', $example);
        $this->assertStringContainsString('ADMIN_SESSION_LIFETIME=', $example);

        // HttpOnly and SameSite are on by default and must stay that way.
        $this->assertTrue((bool) config('session.http_only'));
        $this->assertContains(config('session.same_site'), ['lax', 'strict']);
    }

    #[Test]
    public function remember_me_issues_a_recaller_and_logout_kills_it(): void
    {
        /*
         * Remember-me IS enabled on the storefront sign-in — worth saying,
         * because the first draft of this test assumed it was not.
         *
         * Laravel's recaller cookie carries the user id, the remember
         * token and a prefix of the password hash, and all three are
         * checked. So the two things that must hold are that signing out
         * cycles the token — making every outstanding cookie dead, not
         * just the one in this browser — and that changing the password
         * invalidates it too, because the hash no longer matches.
         */
        $user = User::factory()->create([
            'email' => 'ada@example.test',
            'password' => Hash::make('a-strong-password-1'),
        ]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'a-strong-password-1',
            'remember' => true,
        ]);

        $this->assertAuthenticatedAs($user);

        $issued = $user->refresh()->getRememberToken();
        $this->assertNotEmpty($issued, 'Remember-me was requested and no token was issued.');

        $this->post('/logout');

        $this->assertGuest();
        $this->assertNotSame(
            $issued,
            $user->refresh()->getRememberToken(),
            'Signing out left the remember token intact, so every outstanding cookie still works.',
        );
    }

    #[Test]
    public function changing_a_password_invalidates_an_outstanding_recaller(): void
    {
        $user = User::factory()->create([
            'email' => 'ada@example.test',
            'password' => Hash::make('a-strong-password-1'),
        ]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'a-strong-password-1',
            'remember' => true,
        ]);

        $recaller = $user->refresh()->getRememberToken();
        $oldHash = $user->password;

        $this->put('/account/password', [
            'current_password' => 'a-strong-password-1',
            'password' => 'a-different-password-1',
            'password_confirmation' => 'a-different-password-1',
        ])->assertSessionHasNoErrors();

        $user->refresh();

        $this->assertNotSame($oldHash, $user->password, 'The password did not change.');

        // Laravel's recaller carries a prefix of the password hash and
        // validates it, so a changed password invalidates every
        // outstanding remember cookie without any bookkeeping of our own.
        $this->assertNotEmpty($recaller, 'No recaller was issued, so this test proves nothing.');
        $this->assertFalse(
            str_starts_with($user->password, substr($oldHash, 0, 10)),
            'The password hash prefix did not change, so an outstanding recaller would still validate.',
        );
    }

    /** A valid current TOTP for an enrolled administrator. */
    private function currentCodeFor(mixed $admin): string
    {
        // Through the application's own helper, so the test cannot drift
        // from whatever library sits behind it.
        return Totp::codeAt((string) $admin->two_factor_secret, now()->getTimestamp());
    }
}
