<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Customer identity.
 *
 * Notifications are faked throughout: a test suite must never send mail.
 */
final class CustomerAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    #[Test]
    public function a_customer_can_register(): void
    {
        $this->post('/register', [
            'first_name' => 'Priya',
            'last_name' => 'Raman',
            'email' => 'Priya.R@Example.com',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ])->assertRedirect(route('verification.notice'));

        $user = User::query()->firstOrFail();

        $this->assertSame('priya.r@example.com', $user->email, 'The address is normalised.');
        $this->assertNull($user->email_verified_at, 'A new account starts unverified.');
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame('correct-horse-battery-staple', $user->password, 'The password is hashed.');

        Notification::assertSentTo($user, VerifyEmail::class);
        $this->assertDatabaseHas('audit_logs', ['action' => 'customer.registered']);
    }

    #[Test]
    public function registration_rejects_a_duplicate_address(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->post('/register', [
            'first_name' => 'A',
            'last_name' => 'B',
            'email' => 'taken@example.com',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ])->assertSessionHasErrors('email');

        $this->assertSame(1, User::query()->count());
    }

    #[Test]
    public function registration_rejects_a_mismatched_confirmation(): void
    {
        $this->post('/register', [
            'first_name' => 'A',
            'last_name' => 'B',
            'email' => 'new@example.com',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'something-else-entirely',
        ])->assertSessionHasErrors('password');

        $this->assertSame(0, User::query()->count());
    }

    #[Test]
    public function email_verification_marks_the_account_verified(): void
    {
        $user = User::factory()->unverified()->create();

        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->id,
            'hash' => sha1($user->email),
        ]);

        $this->actingAs($user)->get($url)->assertRedirect(route('home'));

        $this->assertNotNull($user->fresh()?->email_verified_at);
    }

    #[Test]
    public function an_unsigned_verification_link_is_refused(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->get("/verify-email/{$user->id}/".sha1($user->email))
            ->assertForbidden();

        $this->assertNull($user->fresh()?->email_verified_at);
    }

    #[Test]
    public function a_customer_can_sign_in_and_out(): void
    {
        $user = User::factory()->create(['email' => 'shopper@example.com']);

        $this->post('/login', ['email' => 'shopper@example.com', 'password' => 'password'])
            ->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($user);

        $this->post('/logout')->assertRedirect(route('home'));
        $this->assertGuest();
    }

    #[Test]
    public function sign_in_is_case_insensitive_on_the_address(): void
    {
        $user = User::factory()->create(['email' => 'shopper@example.com']);

        $this->post('/login', ['email' => 'SHOPPER@Example.com', 'password' => 'password']);

        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function wrong_credentials_are_refused_without_saying_which_half(): void
    {
        User::factory()->create(['email' => 'shopper@example.com']);

        $response = $this->post('/login', ['email' => 'shopper@example.com', 'password' => 'wrong']);

        $response->assertSessionHasErrors('email');
        $this->assertSame(
            'Those details do not match our records.',
            session('errors')?->first('email'),
        );
        $this->assertGuest();
    }

    #[Test]
    public function repeated_failures_are_throttled(): void
    {
        User::factory()->create(['email' => 'shopper@example.com']);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', ['email' => 'shopper@example.com', 'password' => 'wrong']);
        }

        // The sixth attempt is refused even with the correct password.
        $this->post('/login', ['email' => 'shopper@example.com', 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertStringContainsString('Too many attempts', (string) session('errors')?->first('email'));
    }

    #[Test]
    public function the_session_id_rotates_on_sign_in(): void
    {
        User::factory()->create(['email' => 'shopper@example.com']);

        $this->get('/login');
        $before = session()->getId();

        $this->post('/login', ['email' => 'shopper@example.com', 'password' => 'password']);

        $this->assertNotSame($before, session()->getId(), 'A token captured before sign-in must not survive it.');
    }

    #[Test]
    public function a_reset_link_is_sent_to_a_known_address(): void
    {
        $user = User::factory()->create(['email' => 'shopper@example.com']);

        $this->post('/forgot-password', ['email' => 'shopper@example.com'])->assertSessionHas('status');

        Notification::assertSentTo($user, ResetPassword::class);
    }

    #[Test]
    public function an_unknown_address_gets_the_same_answer(): void
    {
        $response = $this->post('/forgot-password', ['email' => 'nobody@example.com']);

        $response->assertSessionHas('status');
        $this->assertStringContainsString('If an account exists', (string) session('status'));

        Notification::assertNothingSent();
    }

    #[Test]
    public function a_password_can_be_reset_with_a_valid_token(): void
    {
        $user = User::factory()->create(['email' => 'shopper@example.com']);

        $this->post('/forgot-password', ['email' => 'shopper@example.com']);

        $token = '';
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token): bool {
            $token = $notification->token;

            return true;
        });

        $this->post('/reset-password', [
            'token' => $token,
            'email' => 'shopper@example.com',
            'password' => 'a-brand-new-passphrase',
            'password_confirmation' => 'a-brand-new-passphrase',
        ])->assertRedirect(route('login'));

        $this->assertGuest();

        $this->post('/login', ['email' => 'shopper@example.com', 'password' => 'a-brand-new-passphrase']);
        $this->assertAuthenticatedAs($user->fresh());
    }

    #[Test]
    public function an_invalid_reset_token_is_refused(): void
    {
        User::factory()->create(['email' => 'shopper@example.com']);

        $this->post('/reset-password', [
            'token' => 'not-a-real-token',
            'email' => 'shopper@example.com',
            'password' => 'a-brand-new-passphrase',
            'password_confirmation' => 'a-brand-new-passphrase',
        ])->assertSessionHasErrors('email');
    }

    #[Test]
    public function changing_the_password_requires_the_current_one(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/account/password', [
            'current_password' => 'not-my-password',
            'password' => 'a-brand-new-passphrase',
            'password_confirmation' => 'a-brand-new-passphrase',
        ])->assertSessionHasErrors('current_password');
    }

    #[Test]
    public function changing_the_email_address_requires_verification_again(): void
    {
        $user = User::factory()->create(['email' => 'old@example.com']);

        $this->actingAs($user)->put('/account', [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => 'new@example.com',
            'marketing_opt_in' => false,
        ])->assertSessionHas('status');

        $fresh = $user->fresh();
        $this->assertSame('new@example.com', $fresh?->email);
        $this->assertNull($fresh?->email_verified_at, 'A moved address is unverified until proved.');

        Notification::assertSentTo($fresh, VerifyEmail::class);
    }

    #[Test]
    public function a_guest_cannot_reach_the_account_page(): void
    {
        $this->get('/account')->assertRedirect(route('login'));
    }

    #[Test]
    public function the_audit_trail_never_records_a_password(): void
    {
        $this->post('/register', [
            'first_name' => 'Priya',
            'last_name' => 'Raman',
            'email' => 'priya@example.com',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ]);

        foreach (AuditLog::query()->get() as $event) {
            $this->assertStringNotContainsString(
                'correct-horse-battery-staple',
                json_encode($event->toArray()) ?: '',
            );
        }
    }
}
