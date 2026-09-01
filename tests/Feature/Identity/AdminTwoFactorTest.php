<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Actions\BeginTwoFactorEnrolment;
use App\Modules\Identity\Actions\ConfirmTwoFactorEnrolment;
use App\Modules\Identity\Actions\DisableTwoFactor;
use App\Modules\Identity\Actions\RegenerateRecoveryCodes;
use App\Modules\Identity\Actions\VerifyTwoFactorChallenge;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Identity\Models\AdminRecoveryCode;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Identity\Support\Totp;
use Database\Factories\AdminUserFactory;
use Database\Seeders\CommissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Admin multi-factor authentication.
 *
 * The M0 login accepted a code field and ignored it. These tests exist so
 * that can never be true again: each one fails if the second factor stops
 * being enforced.
 */
final class AdminTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private function code(string $secret = AdminUserFactory::TEST_SECRET): string
    {
        return Totp::codeAt($secret, time());
    }

    #[Test]
    public function enrolment_issues_a_secret_and_a_scannable_uri(): void
    {
        $admin = AdminUser::factory()->create();

        $enrolment = app(BeginTwoFactorEnrolment::class)($admin);

        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $enrolment->secret);
        $this->assertStringStartsWith('otpauth://totp/', $enrolment->provisioningUri);
        $this->assertStringContainsString('secret='.$enrolment->secret, $enrolment->provisioningUri);

        // Started, but not yet proved — and therefore not yet protecting.
        $this->assertTrue($admin->fresh()?->isEnrollingTwoFactor());
        $this->assertFalse($admin->fresh()?->hasTwoFactorEnabled());
    }

    #[Test]
    public function enrolment_is_only_complete_once_a_valid_code_proves_it(): void
    {
        $admin = AdminUser::factory()->create();
        $enrolment = app(BeginTwoFactorEnrolment::class)($admin);

        $codes = app(ConfirmTwoFactorEnrolment::class)($admin, Totp::codeAt($enrolment->secret, time()));

        $this->assertTrue($admin->fresh()?->hasTwoFactorEnabled());
        $this->assertCount(8, $codes->codes);
        $this->assertSame(8, $admin->fresh()?->unusedRecoveryCodeCount());
    }

    #[Test]
    public function an_invalid_code_does_not_complete_enrolment(): void
    {
        $admin = AdminUser::factory()->create();
        app(BeginTwoFactorEnrolment::class)($admin);

        try {
            app(ConfirmTwoFactorEnrolment::class)($admin, '000000');
            $this->fail('A wrong code must not confirm enrolment.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertFalse($admin->fresh()?->hasTwoFactorEnabled());
        $this->assertSame(0, AdminRecoveryCode::query()->count(), 'No codes are issued until enrolment is proved.');
    }

    #[Test]
    public function a_valid_totp_code_satisfies_the_challenge(): void
    {
        $admin = AdminUser::factory()->withTwoFactor()->create();

        $this->assertTrue(app(VerifyTwoFactorChallenge::class)($admin, $this->code()));
    }

    #[Test]
    public function an_invalid_totp_code_fails_the_challenge(): void
    {
        $admin = AdminUser::factory()->withTwoFactor()->create();

        $this->assertFalse(app(VerifyTwoFactorChallenge::class)($admin, '000000'));
        $this->assertFalse(app(VerifyTwoFactorChallenge::class)($admin, 'not-a-code'));
        $this->assertFalse(app(VerifyTwoFactorChallenge::class)($admin, ''));
    }

    #[Test]
    public function a_code_from_outside_the_drift_window_is_rejected(): void
    {
        $admin = AdminUser::factory()->withTwoFactor()->create();

        $stale = Totp::codeAt(AdminUserFactory::TEST_SECRET, time() - 300);

        $this->assertFalse(app(VerifyTwoFactorChallenge::class)($admin, $stale));
    }

    #[Test]
    public function an_unconfirmed_secret_can_never_satisfy_a_challenge(): void
    {
        $admin = AdminUser::factory()->enrollingTwoFactor()->create();

        $this->assertFalse(
            app(VerifyTwoFactorChallenge::class)($admin, $this->code()),
            'A secret that was never proved must not authenticate anyone.',
        );
    }

    #[Test]
    public function a_recovery_code_works_exactly_once(): void
    {
        $admin = AdminUser::factory()->create();
        $enrolment = app(BeginTwoFactorEnrolment::class)($admin);
        $codes = app(ConfirmTwoFactorEnrolment::class)($admin, Totp::codeAt($enrolment->secret, time()));

        $first = $codes->codes[0];

        $this->assertTrue(app(VerifyTwoFactorChallenge::class)($admin, $first), 'The code works once.');
        $this->assertFalse(app(VerifyTwoFactorChallenge::class)($admin, $first), 'And never again.');
        $this->assertSame(7, $admin->fresh()?->unusedRecoveryCodeCount());
    }

    #[Test]
    public function recovery_codes_are_stored_hashed_not_in_plaintext(): void
    {
        $admin = AdminUser::factory()->create();
        $enrolment = app(BeginTwoFactorEnrolment::class)($admin);
        $codes = app(ConfirmTwoFactorEnrolment::class)($admin, Totp::codeAt($enrolment->secret, time()));

        $stored = AdminRecoveryCode::query()->pluck('code_hash')->all();

        foreach ($codes->codes as $plaintext) {
            $this->assertNotContains($plaintext, $stored, 'A recovery code must never be stored in the clear.');
        }

        $this->assertTrue(Hash::check($codes->codes[0], $stored[0]));
    }

    #[Test]
    public function regenerating_invalidates_the_previous_codes(): void
    {
        $admin = AdminUser::factory()->create();
        $enrolment = app(BeginTwoFactorEnrolment::class)($admin);
        $old = app(ConfirmTwoFactorEnrolment::class)($admin, Totp::codeAt($enrolment->secret, time()));

        $new = app(RegenerateRecoveryCodes::class)($admin);

        $this->assertFalse(app(VerifyTwoFactorChallenge::class)($admin, $old->codes[0]), 'Old codes stop working.');
        $this->assertTrue(app(VerifyTwoFactorChallenge::class)($admin, $new->codes[0]));

        // Eight were issued and the assertion above spent one of them.
        $this->assertSame(7, $admin->fresh()?->unusedRecoveryCodeCount());
    }

    #[Test]
    public function the_secret_is_never_serialised_after_setup(): void
    {
        $admin = AdminUser::factory()->withTwoFactor()->create();

        $serialised = $admin->toArray();

        $this->assertArrayNotHasKey('two_factor_secret', $serialised);
        $this->assertArrayNotHasKey('password', $serialised);
        $this->assertStringNotContainsString(AdminUserFactory::TEST_SECRET, json_encode($serialised) ?: '');
    }

    #[Test]
    public function login_without_the_code_is_refused_when_mfa_is_enabled(): void
    {
        $admin = AdminUser::factory()->withTwoFactor()->create();

        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertSessionHasErrors('code');

        $this->assertGuest('admin');
    }

    #[Test]
    public function login_with_a_wrong_code_is_refused(): void
    {
        $admin = AdminUser::factory()->withTwoFactor()->create();

        $this->post('/admin/login', [
            'email' => $admin->email,
            'password' => 'password',
            'code' => '000000',
        ])->assertSessionHasErrors('code');

        $this->assertGuest('admin');
    }

    #[Test]
    public function login_with_a_valid_code_succeeds(): void
    {
        $admin = AdminUser::factory()->withTwoFactor()->create();

        $this->post('/admin/login', [
            'email' => $admin->email,
            'password' => 'password',
            'code' => $this->code(),
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin, 'admin');
        $this->assertNotNull($admin->fresh()?->last_login_at);
    }

    #[Test]
    public function an_admin_without_mfa_is_confined_to_enrolment(): void
    {
        $this->seed(CommissionSeeder::class);
        $admin = AdminUser::factory()->role(AdminRole::SellerOperations)->create();

        $this->actingAs($admin, 'admin')->get('/admin')->assertRedirect(route('admin.mfa.setup'));
        $this->actingAs($admin, 'admin')->get(route('admin.mfa.setup'))->assertOk();
    }

    #[Test]
    public function an_admin_with_mfa_reaches_the_dashboard(): void
    {
        $this->seed(CommissionSeeder::class);
        $admin = AdminUser::factory()->role(AdminRole::SellerOperations)->withTwoFactor()->create();

        $this->actingAs($admin, 'admin')->get('/admin')->assertOk();
    }

    #[Test]
    public function repeated_failures_are_throttled(): void
    {
        RateLimiter::clear('admin-login:');
        $admin = AdminUser::factory()->withTwoFactor()->create();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/admin/login', ['email' => $admin->email, 'password' => 'wrong']);
        }

        $this->post('/admin/login', [
            'email' => $admin->email,
            'password' => 'password',
            'code' => $this->code(),
        ])->assertSessionHasErrors('email');

        $this->assertGuest('admin');
        $this->assertNotNull($admin->fresh()?->locked_until, 'The account locks after repeated failures.');
    }

    #[Test]
    public function disabling_mfa_is_audited_without_leaking_the_secret(): void
    {
        $admin = AdminUser::factory()->withTwoFactor()->create();
        $actor = AdminUser::factory()->role(AdminRole::SuperAdmin)->withTwoFactor()->create();
        AdminRecoveryCode::factory()->create(['admin_user_id' => $admin->id]);

        app(DisableTwoFactor::class)($admin, $actor, 'Lost device, verified by phone.');

        $this->assertFalse($admin->fresh()?->hasTwoFactorEnabled());
        $this->assertSame(0, AdminRecoveryCode::query()->where('admin_user_id', $admin->id)->count());

        $event = AuditLog::query()->where('action', 'admin.mfa.disabled')->firstOrFail();

        $this->assertSame('Lost device, verified by phone.', $event->reason);
        $this->assertStringNotContainsString(
            AdminUserFactory::TEST_SECRET,
            json_encode($event->toArray()) ?: '',
            'An audit record must never carry the secret it is about.',
        );
    }

    #[Test]
    public function an_admin_with_the_permission_can_reset_someone_elses_second_factor(): void
    {
        $superAdmin = AdminUser::factory()->role(AdminRole::SuperAdmin)->withTwoFactor()->create();
        $subject = AdminUser::factory()->role(AdminRole::Support)->withTwoFactor()->create();

        $this->actingAs($superAdmin, 'admin')
            ->post("/admin/staff/{$subject->public_id}/reset-two-factor", [
                'reason' => 'Lost the device; identity confirmed on a video call.',
            ])
            ->assertRedirect();

        $fresh = $subject->fresh();
        $this->assertNotNull($fresh);
        $this->assertNull($fresh->two_factor_confirmed_at);
        $this->assertNull($fresh->two_factor_secret);
        $this->assertSame(0, AdminRecoveryCode::query()->where('admin_user_id', $subject->id)->count());
    }

    #[Test]
    public function a_reset_without_a_reason_is_refused(): void
    {
        $superAdmin = AdminUser::factory()->role(AdminRole::SuperAdmin)->withTwoFactor()->create();
        $subject = AdminUser::factory()->role(AdminRole::Support)->withTwoFactor()->create();

        $this->actingAs($superAdmin, 'admin')
            ->post("/admin/staff/{$subject->public_id}/reset-two-factor", ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertNotNull($subject->fresh()?->two_factor_confirmed_at);
    }

    #[Test]
    public function an_admin_without_the_permission_cannot_reset_anyone(): void
    {
        $support = AdminUser::factory()->role(AdminRole::Support)->withTwoFactor()->create();
        $subject = AdminUser::factory()->role(AdminRole::Analyst)->withTwoFactor()->create();

        $this->actingAs($support, 'admin')->get('/admin/staff')->assertForbidden();

        $this->actingAs($support, 'admin')
            ->post("/admin/staff/{$subject->public_id}/reset-two-factor", [
                'reason' => 'They asked me to, over chat.',
            ])
            ->assertForbidden();

        $this->assertNotNull($subject->fresh()?->two_factor_confirmed_at);
    }

    #[Test]
    public function a_reset_is_audited_against_the_admin_who_did_it_and_carries_no_secret(): void
    {
        $superAdmin = AdminUser::factory()->role(AdminRole::SuperAdmin)->withTwoFactor()->create();
        $subject = AdminUser::factory()->role(AdminRole::Support)->withTwoFactor()->create();
        $secret = (string) $subject->two_factor_secret;

        $this->actingAs($superAdmin, 'admin')
            ->post("/admin/staff/{$subject->public_id}/reset-two-factor", [
                'reason' => 'Lost the device; identity confirmed on a video call.',
            ]);

        $entry = AuditLog::query()->where('action', 'admin.mfa.disabled')->firstOrFail();

        $this->assertSame($superAdmin->id, $entry->actor_id);
        $this->assertSame($subject->id, $entry->subject_id);
        $this->assertStringNotContainsString($secret, json_encode($entry->changes).(string) $entry->reason);
    }
}
