<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Identity\Models\AdminRecoveryCode;
use App\Modules\Identity\Models\AdminUser;
use Illuminate\Support\Facades\DB;

/**
 * Removes the second factor and every recovery code with it.
 *
 * Always audited, and never silently: turning off MFA on a staff account
 * is one of the few actions that materially widens the platform's attack
 * surface, so it leaves a record naming who did it and why.
 */
final class DisableTwoFactor
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function __invoke(AdminUser $admin, AdminUser $actor, string $reason): void
    {
        DB::transaction(function () use ($admin, $actor, $reason): void {
            AdminRecoveryCode::query()->where('admin_user_id', $admin->id)->delete();

            $admin->forceFill([
                'two_factor_secret' => null,
                'two_factor_enrolled_at' => null,
                'two_factor_confirmed_at' => null,
            ])->save();

            // The record names the account and the reason. It never carries
            // the secret or any recovery code.
            ($this->audit)(
                action: 'admin.mfa.disabled',
                actorType: 'admin',
                actorId: $actor->id,
                subjectType: AdminUser::class,
                subjectId: $admin->id,
                reason: $reason,
                changes: ['two_factor_confirmed_at' => ['from' => 'set', 'to' => null]],
            );
        });
    }
}
