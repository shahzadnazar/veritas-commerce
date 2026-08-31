<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Identity\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class ProfileController
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function show(Request $request): Response
    {
        $user = $this->user($request);

        return Inertia::render('Account/Profile', [
            'profile' => [
                'firstName' => $user->first_name,
                'lastName' => $user->last_name,
                'email' => $user->email,
                'phone' => $user->phone,
                'marketingOptIn' => $user->marketing_opt_in,
                'emailVerified' => $user->hasVerifiedEmail(),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $this->user($request);

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:40'],
            'marketing_opt_in' => ['boolean'],
        ]);

        $emailChanged = strtolower($validated['email']) !== $user->email;

        $user->fill([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'email' => strtolower($validated['email']),
            'phone' => $validated['phone'] ?? null,
            'marketing_opt_in' => $validated['marketing_opt_in'] ?? false,
        ]);

        if ($emailChanged) {
            // A changed address is unverified until proved, so an account
            // cannot be moved to an address the owner does not control.
            $user->email_verified_at = null;
        }

        $user->save();

        if ($emailChanged) {
            $user->sendEmailVerificationNotification();
        }

        ($this->audit)(
            action: 'customer.profile_updated',
            actorType: 'customer',
            actorId: $user->id,
            subjectType: User::class,
            subjectId: $user->id,
            changes: ['email_changed' => $emailChanged],
        );

        return back()->with('status', 'Your details are saved.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $user = $this->user($request);

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        // Changing the password proves the current one, so a borrowed
        // session cannot lock the owner out.
        if (! Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'That is not your current password.',
            ]);
        }

        $user->forceFill(['password' => $validated['password']])->save();

        ($this->audit)(
            action: 'customer.password_changed',
            actorType: 'customer',
            actorId: $user->id,
            subjectType: User::class,
            subjectId: $user->id,
        );

        return back()->with('status', 'Your password is updated.');
    }

    private function user(Request $request): User
    {
        $user = $request->user('web');

        abort_if(! $user instanceof User, 403);

        return $user;
    }
}
