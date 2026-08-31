<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\AdminRecoveryCode;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Identity\Support\Totp;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Answers one question: does this input satisfy the second factor?
 *
 * A TOTP code is accepted within the drift window. A recovery code is
 * accepted exactly once — it is marked used inside a transaction that
 * locks the row, so two simultaneous requests cannot both spend it.
 */
final class VerifyTwoFactorChallenge
{
    public function __invoke(AdminUser $admin, string $code, ?string $ip = null): bool
    {
        $secret = $admin->two_factor_secret;

        if ($secret === null || $admin->two_factor_confirmed_at === null) {
            return false;
        }

        if (Totp::verify($secret, $code)) {
            return true;
        }

        return $this->consumeRecoveryCode($admin, $code, $ip);
    }

    private function consumeRecoveryCode(AdminUser $admin, string $code, ?string $ip): bool
    {
        $candidate = strtoupper(trim($code));

        return DB::transaction(function () use ($admin, $candidate, $ip): bool {
            $unused = AdminRecoveryCode::query()
                ->where('admin_user_id', $admin->id)
                ->whereNull('used_at')
                ->lockForUpdate()
                ->get();

            foreach ($unused as $recoveryCode) {
                if (! Hash::check($candidate, $recoveryCode->code_hash)) {
                    continue;
                }

                $recoveryCode->update([
                    'used_at' => Carbon::now(),
                    'used_ip' => $ip,
                ]);

                return true;
            }

            return false;
        });
    }
}
