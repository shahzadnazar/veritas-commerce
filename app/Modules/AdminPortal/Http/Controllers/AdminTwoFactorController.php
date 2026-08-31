<?php

declare(strict_types=1);

namespace App\Modules\AdminPortal\Http\Controllers;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Identity\Actions\BeginTwoFactorEnrolment;
use App\Modules\Identity\Actions\ConfirmTwoFactorEnrolment;
use App\Modules\Identity\Actions\RegenerateRecoveryCodes;
use App\Modules\Identity\Models\AdminUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Enrolment, confirmation and recovery-code management.
 *
 * Every write here re-checks the administrator's password: possession of a
 * live session is not enough to change the thing that protects the session.
 */
final class AdminTwoFactorController
{
    public function __construct(
        private readonly BeginTwoFactorEnrolment $begin,
        private readonly ConfirmTwoFactorEnrolment $confirm,
        private readonly RegenerateRecoveryCodes $regenerate,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function setup(Request $request): Response
    {
        $admin = $this->admin($request);

        // Show the current state; the secret is only issued when the
        // administrator asks to start, not on every page view.
        return Inertia::render('TwoFactorSetup', [
            'enabled' => $admin->hasTwoFactorEnabled(),
            'enrolling' => $admin->isEnrollingTwoFactor(),
            'recoveryCodesRemaining' => $admin->unusedRecoveryCodeCount(),
            // Flashed by start()/store(); present for one render only.
            'enrolment' => session('enrolment'),
            'recoveryCodes' => session('recoveryCodes'),
        ]);
    }

    public function start(Request $request): RedirectResponse
    {
        $admin = $this->admin($request);
        $this->confirmPassword($request, $admin);

        $enrolment = ($this->begin)($admin);

        ($this->audit)(
            action: 'admin.mfa.enrolment_started',
            actorType: 'admin',
            actorId: $admin->id,
            subjectType: AdminUser::class,
            subjectId: $admin->id,
        );

        // The secret and URI are flashed for exactly one render. They are
        // never persisted into a response the browser can revisit.
        return back()->with('enrolment', [
            'secret' => $enrolment->secret,
            'uri' => $enrolment->provisioningUri,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $admin = $this->admin($request);

        $validated = $request->validate([
            'code' => ['required', 'string', 'size:6'],
        ]);

        try {
            $codes = ($this->confirm)($admin, $validated['code']);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['code' => $exception->getMessage()]);
        }

        ($this->audit)(
            action: 'admin.mfa.enabled',
            actorType: 'admin',
            actorId: $admin->id,
            subjectType: AdminUser::class,
            subjectId: $admin->id,
        );

        return redirect()->route('admin.mfa.setup')->with('recoveryCodes', $codes->codes);
    }

    public function regenerate(Request $request): RedirectResponse
    {
        $admin = $this->admin($request);
        $this->confirmPassword($request, $admin);

        abort_unless($admin->hasTwoFactorEnabled(), 400);

        $codes = ($this->regenerate)($admin);

        ($this->audit)(
            action: 'admin.mfa.recovery_codes_regenerated',
            actorType: 'admin',
            actorId: $admin->id,
            subjectType: AdminUser::class,
            subjectId: $admin->id,
        );

        return back()->with('recoveryCodes', $codes->codes);
    }

    private function admin(Request $request): AdminUser
    {
        $admin = $request->user('admin');

        abort_if(! $admin instanceof AdminUser, 403);

        return $admin;
    }

    /**
     * Sensitive actions re-prove the password.
     *
     * A borrowed session should not be able to rotate recovery codes or
     * re-enrol a device.
     */
    private function confirmPassword(Request $request, AdminUser $admin): void
    {
        $password = (string) $request->input('password', '');

        if ($password === '' || ! Hash::check($password, $admin->password)) {
            throw ValidationException::withMessages([
                'password' => 'Confirm your password to continue.',
            ]);
        }
    }
}
