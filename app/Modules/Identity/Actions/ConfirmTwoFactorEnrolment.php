<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Data\RecoveryCodes;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Identity\Support\Totp;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Proves the administrator can generate codes, then turns the factor on.
 *
 * Recovery codes are issued here rather than at enrolment start, so a
 * half-finished setup never leaves usable codes behind.
 */
final class ConfirmTwoFactorEnrolment
{
    public function __construct(private readonly RegenerateRecoveryCodes $regenerate) {}

    public function __invoke(AdminUser $admin, string $code): RecoveryCodes
    {
        $secret = $admin->two_factor_secret;

        if ($secret === null || $admin->two_factor_enrolled_at === null) {
            throw new RuntimeException('Start enrolment before confirming it.');
        }

        if (! Totp::verify($secret, $code)) {
            throw new RuntimeException('That code did not match. Check your authenticator and try again.');
        }

        $admin->forceFill(['two_factor_confirmed_at' => Carbon::now()])->save();

        return ($this->regenerate)($admin);
    }
}
