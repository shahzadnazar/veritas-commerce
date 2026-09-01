<?php

declare(strict_types=1);

namespace App\Modules\AdminPortal\Http\Controllers;

use App\Modules\AdminPortal\Http\Requests\DecisionRequest;
use App\Modules\Identity\Actions\DisableTwoFactor;
use App\Modules\Identity\Enums\AdminPermission;
use App\Modules\Identity\Models\AdminUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff accounts, and the one operation M1 needs on them.
 *
 * Resetting someone's second factor is a genuine escalation — it is the
 * step that turns "I lost my phone" into "somebody else can sign in as
 * them" if it is done carelessly — so it needs its own permission, a
 * written reason, and an audit record naming who did it.
 */
final class AdminStaffController
{
    public function __construct(private readonly DisableTwoFactor $disableTwoFactor) {}

    public function index(Request $request): Response
    {
        $this->authorize($request, AdminPermission::ResetAdminMfa);

        $staff = AdminUser::query()->orderBy('name')->get();

        return Inertia::render('Staff/Index', [
            'staff' => $staff
                ->map(static fn (AdminUser $admin): array => [
                    'publicId' => $admin->public_id,
                    'name' => $admin->name,
                    'email' => $admin->email,
                    'role' => $admin->role->value,
                    'roleLabel' => $admin->role->label(),
                    // Whether a second factor is set, never anything about
                    // what it is.
                    'twoFactorConfirmed' => $admin->two_factor_confirmed_at !== null,
                    'lastSignedInAt' => $admin->last_login_at?->toDayDateTimeString(),
                ])
                ->all(),
        ]);
    }

    public function resetTwoFactor(DecisionRequest $request, string $publicId): RedirectResponse
    {
        $this->authorize($request, AdminPermission::ResetAdminMfa);

        $actor = $this->admin($request);
        $subject = AdminUser::query()->where('public_id', $publicId)->firstOrFail();

        if ($subject->id === $actor->id) {
            // Resetting your own factor from a session you are already
            // inside proves nothing. Enrolment has its own flow, behind a
            // password re-confirmation.
            throw ValidationException::withMessages([
                'reason' => 'Use your own two-factor settings to change your enrolment.',
            ]);
        }

        ($this->disableTwoFactor)($subject, $actor, $request->reason());

        return back()->with('success', "{$subject->name} must enrol a new second factor at their next sign-in.");
    }

    private function admin(Request $request): AdminUser
    {
        $admin = $request->user('admin');

        abort_if(! $admin instanceof AdminUser, 403);

        return $admin;
    }

    private function authorize(Request $request, AdminPermission $permission): void
    {
        abort_unless($this->admin($request)->role->can($permission), 403);
    }
}
