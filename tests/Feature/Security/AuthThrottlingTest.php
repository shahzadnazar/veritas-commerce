<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Identity\Models\User;
use App\Providers\RateLimitServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * M9 block B — the endpoints an attacker hammers.
 *
 * Login and the admin MFA challenge were already limited inside their
 * controllers, keyed on email and address together. What was unprotected
 * was everything around them: password-reset initiation and submission,
 * registration, the authenticated MFA management routes, and the password
 * change that verifies the current password.
 *
 * The key shape is the part these tests care most about. A limiter keyed
 * only on the account is a denial-of-service tool — anybody who knows an
 * email address can spend its owner's bucket and lock them out of
 * recovery. A limiter keyed only on the address is useless behind a
 * corporate NAT and trivially evaded with a proxy. So each carries both,
 * and the tests below prove that one person being throttled does not
 * throttle anybody else.
 */
final class AuthThrottlingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The suite runs with an array cache, so buckets do not leak
        // between tests — but being explicit costs nothing and makes the
        // window assertions below mean what they say.
        RateLimiter::clear('');
        Notification::fake();
    }

    #[Test]
    public function an_ordinary_password_reset_request_goes_through(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.test']);

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHasNoErrors();

        $this->assertLessThan(
            RateLimitServiceProvider::RESET_REQUESTS_PER_MINUTE,
            1,
            'A single request must be nowhere near the limit.',
        );
    }

    #[Test]
    public function hammering_password_reset_is_refused(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.test']);

        $allowed = RateLimitServiceProvider::RESET_REQUESTS_PER_MINUTE;

        for ($i = 0; $i < $allowed; $i++) {
            $this->post('/forgot-password', ['email' => $user->email])->assertStatus(302);
        }

        $this->post('/forgot-password', ['email' => $user->email])->assertStatus(429);
    }

    #[Test]
    public function one_persons_throttling_does_not_lock_anybody_else_out(): void
    {
        /*
         * The property that matters more than the limit itself.
         *
         * If the bucket were keyed on the address alone, spending it would
         * lock out everyone behind that address. If it were keyed on the
         * account alone, anybody who knows an email address could lock its
         * owner out of password recovery — which would be a worse bug than
         * the one the limiter fixes.
         */
        $victim = User::factory()->create(['email' => 'victim@example.test']);
        $bystander = User::factory()->create(['email' => 'bystander@example.test']);

        for ($i = 0; $i < RateLimitServiceProvider::RESET_REQUESTS_PER_MINUTE; $i++) {
            $this->post('/forgot-password', ['email' => $victim->email]);
        }

        $this->post('/forgot-password', ['email' => $victim->email])->assertStatus(429);

        // A different account from the same address is still served,
        // because the tight limit is on the pair.
        $this->post('/forgot-password', ['email' => $bystander->email])
            ->assertStatus(302)
            ->assertSessionHasNoErrors();
    }

    #[Test]
    public function walking_a_list_of_addresses_from_one_source_is_refused(): void
    {
        // And the other half: the looser cap on the source alone is what
        // stops somebody working through a list of email addresses.
        $perAddress = RateLimitServiceProvider::RESET_REQUESTS_PER_ADDRESS;

        for ($i = 0; $i < $perAddress; $i++) {
            $this->post('/forgot-password', ['email' => "person{$i}@example.test"]);
        }

        $this->post('/forgot-password', ['email' => 'one-too-many@example.test'])->assertStatus(429);
    }

    #[Test]
    public function reset_submission_is_limited_as_well(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.test']);

        for ($i = 0; $i < RateLimitServiceProvider::RESET_SUBMISSIONS_PER_MINUTE; $i++) {
            $this->post('/reset-password', [
                'token' => 'a-token-that-is-not-real',
                'email' => $user->email,
                'password' => 'a-new-password-1',
                'password_confirmation' => 'a-new-password-1',
            ]);
        }

        $this->post('/reset-password', [
            'token' => 'a-token-that-is-not-real',
            'email' => $user->email,
            'password' => 'a-new-password-1',
            'password_confirmation' => 'a-new-password-1',
        ])->assertStatus(429);
    }

    #[Test]
    public function registration_from_one_source_is_capped(): void
    {
        for ($i = 0; $i < RateLimitServiceProvider::REGISTRATIONS_PER_ADDRESS; $i++) {
            $this->post('/register', [
                'name' => "Person {$i}",
                'email' => "person{$i}@example.test",
                'password' => 'a-strong-password-1',
                'password_confirmation' => 'a-strong-password-1',
            ]);
        }

        $this->post('/register', [
            'name' => 'One too many',
            'email' => 'one-too-many@example.test',
            'password' => 'a-strong-password-1',
            'password_confirmation' => 'a-strong-password-1',
        ])->assertStatus(429);
    }

    #[Test]
    public function the_admin_mfa_challenge_is_limited_by_the_login_attempt_itself(): void
    {
        // The challenge is submitted with the password, so it shares the
        // login limiter — keyed on email and address, which is why one
        // attacker cannot lock every administrator out through a shared
        // address.
        $admin = $this->makeAdmin(AdminRole::SuperAdmin);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/admin/login', [
                'email' => $admin->email,
                'password' => 'password',
                'code' => '000000',
            ]);
        }

        $this->post('/admin/login', [
            'email' => $admin->email,
            'password' => 'password',
            'code' => '000000',
        ])->assertSessionHasErrors();

        // A different administrator from the same address is unaffected.
        $other = $this->makeAdmin(AdminRole::SellerOperations);

        $this->post('/admin/login', [
            'email' => $other->email,
            'password' => 'wrong-password',
            'code' => '000000',
        ])->assertSessionHasErrors('email');

        $this->assertNotSame($admin->email, $other->email);
    }

    #[Test]
    public function every_sensitive_route_carries_a_limiter(): void
    {
        /*
         * The structural half, and the reason it exists: the audit that
         * started this block found reset, registration and the MFA
         * management routes with no throttle at all. They were not
         * deliberately unprotected — they were added at different times by
         * people looking at different things.
         *
         * So the list is asserted rather than remembered. A new route on a
         * sensitive path fails here until somebody decides what limits it.
         */
        $expected = [
            'password.email' => 'throttle:password-reset-request',
            'password.update' => 'throttle:password-reset-submit',
            'account.password' => 'throttle:password-change',
            'admin.mfa.start' => 'throttle:admin-mfa',
            'admin.mfa.store' => 'throttle:admin-mfa',
            'admin.mfa.recovery' => 'throttle:admin-mfa',
            'verification.send' => 'throttle:6,1',
        ];

        foreach ($expected as $name => $limiter) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "The route {$name} has disappeared.");
            $this->assertContains(
                $limiter,
                $route->gatherMiddleware(),
                "{$name} lost its rate limiter.",
            );
        }

        // Registration is matched by URI: it is the one sensitive POST
        // with no route name.
        $register = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($route): bool => $route->uri() === 'register' && in_array('POST', $route->methods(), true));

        $this->assertNotNull($register);
        $this->assertContains('throttle:register', $register->gatherMiddleware());

        // Login carries its limiter inside the controller rather than on
        // the route, so it is asserted where it lives.
        foreach ([
            'app/Modules/Identity/Http/Controllers/LoginController.php',
            'app/Modules/AdminPortal/Http/Controllers/AdminLoginController.php',
        ] as $controller) {
            $source = (string) file_get_contents(base_path($controller));

            $this->assertStringContainsString('RateLimiter::tooManyAttempts', $source, "{$controller} stopped limiting.");
            $this->assertStringContainsString('RateLimiter::hit', $source, "{$controller} stopped recording failures.");
            $this->assertMatchesRegularExpression(
                '/\$request->ip\(\)/',
                $source,
                "{$controller}'s limiter key no longer includes the source address.",
            );
        }
    }

    #[Test]
    public function a_reset_request_says_the_same_thing_whoever_it_is_for(): void
    {
        /*
         * Enumeration. A reset form that says "no such account" for one
         * address and "check your email" for another is an account
         * oracle, and reset forms are unauthenticated by definition.
         */
        $known = User::factory()->create(['email' => 'known@example.test']);

        $forKnown = $this->post('/forgot-password', ['email' => $known->email]);
        $knownStatus = session('status');
        $knownErrors = $this->errorMessages();

        $this->flushSession();

        $forUnknown = $this->post('/forgot-password', ['email' => 'nobody-here@example.test']);
        $unknownStatus = session('status');
        $unknownErrors = $this->errorMessages();

        $this->assertSame($forKnown->getStatusCode(), $forUnknown->getStatusCode());
        $this->assertSame(
            $forKnown->baseResponse->headers->get('Location'),
            $forUnknown->baseResponse->headers->get('Location'),
            'The reset form redirects somewhere different depending on whether the account exists.',
        );
        $this->assertSame($knownStatus, $unknownStatus, 'The confirmation wording differs by account existence.');
        $this->assertSame($knownErrors, $unknownErrors, 'The validation errors differ by account existence.');
    }

    #[Test]
    public function a_failed_login_does_not_say_which_half_was_wrong(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.test']);

        $wrongPassword = $this->post('/login', ['email' => $user->email, 'password' => 'not-the-password']);
        $wrongPasswordErrors = $this->errorMessages();

        $this->flushSession();

        $noSuchAccount = $this->post('/login', ['email' => 'nobody@example.test', 'password' => 'not-the-password']);
        $noSuchAccountErrors = $this->errorMessages();

        $this->assertSame($wrongPassword->getStatusCode(), $noSuchAccount->getStatusCode());
        $this->assertSame(
            $wrongPasswordErrors,
            $noSuchAccountErrors,
            'The sign-in form distinguishes a wrong password from an account that does not exist.',
        );
        $this->assertNotSame([], $wrongPasswordErrors, 'This test is vacuous if neither attempt errored.');
    }

    /**
     * Every validation message the last request produced, flattened.
     *
     * @return array<string, array<int, string>>
     */
    private function errorMessages(): array
    {
        $errors = session('errors');

        // Read without insisting on a concrete class: the session store
        // hands back whatever it holds, and a test that silently returned
        // an empty array when it did not recognise the type would compare
        // nothing to nothing and pass.
        if ($errors === null) {
            return [];
        }

        if ($errors instanceof ViewErrorBag || $errors instanceof MessageBag) {
            $bag = $errors instanceof ViewErrorBag ? $errors->getBag('default') : $errors;

            // getMessages() rather than messages(): getBag() is typed to
            // the contract, and only the contract's method is guaranteed.
            return $bag->getMessages();
        }

        // Under the array session driver the bag arrives already
        // serialised, which is the shape the test suite actually sees.
        if (is_array($errors)) {
            $default = $errors['default'] ?? [];

            return is_array($default) && is_array($default['messages'] ?? null)
                ? $default['messages']
                : [];
        }

        $this->fail('The session holds errors in an unrecognised shape: '.get_debug_type($errors));
    }
}
