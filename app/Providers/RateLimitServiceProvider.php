<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Named limiters for the endpoints an attacker hammers.
 *
 * Login and the admin MFA challenge are already limited inside their
 * controllers, keyed on email and address together, so those are not
 * repeated here. What was unprotected was everything around them:
 * password-reset initiation, reset submission, registration, and the
 * authenticated MFA management routes.
 *
 * THE KEY SHAPE, which is the part worth thinking about.
 *
 * A limiter keyed only on the account is a denial-of-service tool: anybody
 * who knows an email address can lock its owner out of password recovery
 * by spending the bucket. A limiter keyed only on the address is useless
 * behind a corporate NAT or a mobile carrier, where thousands of
 * legitimate people share one address, and is trivially evaded by anybody
 * with a proxy pool.
 *
 * So each of these carries two limits at once: a tight one on the pair,
 * which is what stops an attacker working on one victim, and a looser one
 * on the address alone, which is what stops somebody walking a list of
 * addresses. Exceeding either refuses the request; neither one on its own
 * can lock a stranger out.
 */
final class RateLimitServiceProvider extends ServiceProvider
{
    /**
     * How many reset emails one person can ask for before it is abuse.
     *
     * Generous enough for somebody who mistyped their address, refilled
     * their inbox and tried again; far below what it takes to use this
     * platform as a mail cannon aimed at somebody else's inbox.
     */
    public const RESET_REQUESTS_PER_MINUTE = 3;

    public const RESET_REQUESTS_PER_ADDRESS = 10;

    /**
     * Reset submission. The token's entropy is the real defence; this is
     * the second lock, so it is looser than the request limit — somebody
     * pasting a token from an email and mistyping their new password twice
     * is normal.
     */
    public const RESET_SUBMISSIONS_PER_MINUTE = 5;

    public const RESET_SUBMISSIONS_PER_ADDRESS = 20;

    public const REGISTRATIONS_PER_ADDRESS = 5;

    /**
     * Authenticated MFA management: enrolment confirmation, which verifies
     * a code, and recovery-code regeneration. Keyed on the admin rather
     * than the address, because you have to already be that admin to
     * reach these — there is no stranger to lock out.
     */
    public const MFA_MANAGEMENT_PER_MINUTE = 6;

    /**
     * Changing a password proves the current one, which means a borrowed
     * session can guess at it. Keyed on the account rather than the
     * address, because only the account holder's own session can reach it.
     */
    public const PASSWORD_CHANGES_PER_MINUTE = 6;

    public function boot(): void
    {
        RateLimiter::for('password-reset-request', fn (Request $request): array => [
            Limit::perMinute(self::RESET_REQUESTS_PER_MINUTE)->by($this->accountAndAddress($request, 'reset-request')),
            Limit::perMinute(self::RESET_REQUESTS_PER_ADDRESS)->by('reset-request-ip:'.$request->ip()),
        ]);

        RateLimiter::for('password-reset-submit', fn (Request $request): array => [
            Limit::perMinute(self::RESET_SUBMISSIONS_PER_MINUTE)->by($this->accountAndAddress($request, 'reset-submit')),
            Limit::perMinute(self::RESET_SUBMISSIONS_PER_ADDRESS)->by('reset-submit-ip:'.$request->ip()),
        ]);

        // Registration has no victim to protect, so the address alone is
        // the right key: what this stops is one source opening accounts in
        // bulk.
        RateLimiter::for('register', fn (Request $request): Limit => Limit::perMinute(self::REGISTRATIONS_PER_ADDRESS)
            ->by('register-ip:'.$request->ip()));

        RateLimiter::for('password-change', function (Request $request): Limit {
            $user = $request->user('web');

            return Limit::perMinute(self::PASSWORD_CHANGES_PER_MINUTE)->by(
                'password-change:'.($user?->getAuthIdentifier() ?? 'guest').'|'.$request->ip(),
            );
        });

        RateLimiter::for('admin-mfa', function (Request $request): Limit {
            $admin = $request->user('admin');

            return Limit::perMinute(self::MFA_MANAGEMENT_PER_MINUTE)->by(
                'admin-mfa:'.($admin?->getAuthIdentifier() ?? 'guest').'|'.$request->ip(),
            );
        });
    }

    /**
     * The pair: who is being acted on, and where from.
     *
     * The address is part of the key deliberately. Without it this becomes
     * a way to lock any account whose email address you can guess, which
     * is a worse bug than the one the limiter is here to fix.
     */
    private function accountAndAddress(Request $request, string $scope): string
    {
        $email = $request->input('email');
        $email = is_string($email) ? mb_strtolower(trim($email)) : '';

        return $scope.':'.$email.'|'.$request->ip();
    }
}
