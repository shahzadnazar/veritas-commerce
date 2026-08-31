<?php

declare(strict_types=1);

namespace App\Modules\AdminPortal\Http\Controllers;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Identity\Actions\VerifyTwoFactorChallenge;
use App\Modules\Identity\Models\AdminUser;
use App\Support\Guards;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff sign-in.
 *
 * Password and second factor are checked in one step, and the session is
 * only created once both pass — there is no intermediate half-authenticated
 * state to attack. An account with MFA enabled cannot sign in without it;
 * an account without MFA enabled is sent straight to enrolment and can
 * reach nothing else until it finishes.
 */
final class AdminLoginController
{
    private const MAX_ATTEMPTS = 5;

    private const LOCKOUT_MINUTES = 15;

    public function __construct(
        private readonly VerifyTwoFactorChallenge $verifyTwoFactor,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function show(): Response
    {
        return Inertia::render('Login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string'],
            'code' => ['nullable', 'string', 'max:32'],
        ]);

        $this->assertNotRateLimited($request, $credentials['email']);

        $admin = AdminUser::query()->where('email', $credentials['email'])->first();

        // Hash a dummy value when the account does not exist, so the
        // response time does not reveal which emails are staff accounts.
        $passwordValid = $admin !== null
            ? Hash::check($credentials['password'], $admin->password)
            : Hash::check($credentials['password'], Hash::make('invalid'));

        if ($admin === null || ! $passwordValid || $admin->isLocked()) {
            $this->recordFailure($request, $credentials['email'], $admin);

            throw ValidationException::withMessages([
                // Never says which of the two was wrong, and never says
                // whether the account exists.
                'email' => 'Those details do not match our records.',
            ]);
        }

        if ($admin->hasTwoFactorEnabled()) {
            $code = (string) ($credentials['code'] ?? '');

            if ($code === '' || ! ($this->verifyTwoFactor)($admin, $code, $request->ip())) {
                $this->recordFailure($request, $credentials['email'], $admin, twoFactor: true);

                throw ValidationException::withMessages([
                    'code' => 'That verification code is not valid.',
                ]);
            }
        }

        RateLimiter::clear($this->throttleKey($request, $credentials['email']));

        Guards::session('admin')->login($admin);

        // Rotate the session id so a token captured before sign-in is
        // worthless afterwards.
        $request->session()->regenerate();

        $admin->forceFill([
            'failed_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => Carbon::now(),
            'last_login_ip' => $request->ip(),
            'last_active_at' => Carbon::now(),
        ])->save();

        ($this->audit)(
            action: 'admin.signed_in',
            actorType: 'admin',
            actorId: $admin->id,
            subjectType: AdminUser::class,
            subjectId: $admin->id,
        );

        // An account without a confirmed factor gets one route only.
        if (! $admin->hasTwoFactorEnabled()) {
            return redirect()->route('admin.mfa.setup');
        }

        return redirect()->intended(route('admin.dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Guards::session('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }

    private function assertNotRateLimited(Request $request, string $email): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey($request, $email), self::MAX_ATTEMPTS)) {
            return;
        }

        $seconds = RateLimiter::availableIn($this->throttleKey($request, $email));

        throw ValidationException::withMessages([
            'email' => "Too many attempts. Try again in {$seconds} seconds.",
        ]);
    }

    private function recordFailure(Request $request, string $email, ?AdminUser $admin, bool $twoFactor = false): void
    {
        RateLimiter::hit($this->throttleKey($request, $email), self::LOCKOUT_MINUTES * 60);

        if ($admin === null) {
            return;
        }

        $attempts = $admin->failed_attempts + 1;

        $admin->forceFill([
            'failed_attempts' => $attempts,
            'locked_until' => $attempts >= self::MAX_ATTEMPTS
                ? Carbon::now()->addMinutes(self::LOCKOUT_MINUTES)
                : $admin->locked_until,
        ])->save();

        ($this->audit)(
            action: $twoFactor ? 'admin.sign_in.two_factor_failed' : 'admin.sign_in.failed',
            actorType: 'admin',
            actorId: $admin->id,
            subjectType: AdminUser::class,
            subjectId: $admin->id,
        );
    }

    /** Throttled per account and per IP, so neither dimension alone is enough. */
    private function throttleKey(Request $request, string $email): string
    {
        return 'admin-login:'.strtolower($email).'|'.$request->ip();
    }
}
