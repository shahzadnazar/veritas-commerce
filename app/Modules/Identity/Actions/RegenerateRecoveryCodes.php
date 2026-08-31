<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Data\RecoveryCodes;
use App\Modules\Identity\Models\AdminRecoveryCode;
use App\Modules\Identity\Models\AdminUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Replaces every recovery code with a fresh set.
 *
 * Regenerating invalidates the old set completely, including unused codes:
 * a set that has been partially spent, or that might have been seen, is
 * not one to keep half of.
 */
final class RegenerateRecoveryCodes
{
    public const COUNT = 8;

    public function __invoke(AdminUser $admin): RecoveryCodes
    {
        return DB::transaction(function () use ($admin): RecoveryCodes {
            AdminRecoveryCode::query()->where('admin_user_id', $admin->id)->delete();

            $plaintext = [];

            for ($index = 0; $index < self::COUNT; $index++) {
                // Grouped for legibility when someone copies them down.
                $code = strtoupper(Str::random(5).'-'.Str::random(5));
                $plaintext[] = $code;

                AdminRecoveryCode::query()->create([
                    'admin_user_id' => $admin->id,
                    'code_hash' => Hash::make($code),
                    'created_at' => Carbon::now(),
                ]);
            }

            return new RecoveryCodes($plaintext);
        });
    }
}
