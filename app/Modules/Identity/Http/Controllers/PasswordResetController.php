<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Support\Guards;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Forgotten-password request and reset.
 *
 * The confirmation is deliberately identical whether or not the address
 * has an account: anything else turns this form into a way to enumerate
 * customers.
 */
final class PasswordResetController
{
    public function request(): Response
    {
        return Inertia::render('Auth/ForgotPassword');
    }

    public function email(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        Password::broker()->sendResetLink(['email' => strtolower($validated['email'])]);

        return back()->with('status', 'If an account exists for that address, a reset link is on its way.');
    }

    public function reset(Request $request, string $token): Response
    {
        return Inertia::render('Auth/ResetPassword', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::broker()->reset(
            [
                'email' => strtolower($validated['email']),
                'password' => $validated['password'],
                'password_confirmation' => $request->input('password_confirmation'),
                'token' => $validated['token'],
            ],
            function ($user) use ($validated): void {
                $user->forceFill([
                    'password' => $validated['password'],
                    'remember_token' => Str::random(60),
                ])->save();

                Event::dispatch(new PasswordReset($user));
            },
        );

        if ($status !== Password::PasswordReset) {
            throw ValidationException::withMessages([
                'email' => 'That reset link is no longer valid. Request a new one.',
            ]);
        }

        // Every other session for this account is invalidated by the new
        // remember token; sign the user in fresh on this one.
        Guards::session('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'Your password has been reset. Sign in with it.');
    }
}
