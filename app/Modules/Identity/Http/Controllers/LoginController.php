<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Customer sign-in.
 *
 * Throttled per account and per IP so neither dimension alone gets an
 * attacker very far, and the session id is rotated on success so a token
 * captured beforehand is worthless.
 */
final class LoginController
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    public function show(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
            'remember' => ['boolean'],
        ]);

        $key = $this->throttleKey($request, $credentials['email']);

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'email' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.',
            ]);
        }

        $guard = Auth::guard('web');

        if (! $guard instanceof StatefulGuard) {
            throw new RuntimeException('The web guard must be session-based.');
        }

        $succeeded = $guard->attempt(
            ['email' => strtolower($credentials['email']), 'password' => $credentials['password']],
            (bool) ($credentials['remember'] ?? false),
        );

        if (! $succeeded) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            throw ValidationException::withMessages([
                // Says nothing about which half was wrong, or whether the
                // account exists.
                'email' => 'Those details do not match our records.',
            ]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        return redirect()->intended(route('home'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }

    private function throttleKey(Request $request, string $email): string
    {
        return 'login:'.strtolower($email).'|'.$request->ip();
    }
}
