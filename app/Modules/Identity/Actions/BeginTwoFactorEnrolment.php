<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Data\TwoFactorEnrolment;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Identity\Support\Totp;
use Illuminate\Support\Carbon;

/**
 * Issues a secret and the URI an authenticator app scans.
 *
 * The secret is stored encrypted but deliberately NOT confirmed: until the
 * administrator proves they can generate a code from it, it cannot satisfy
 * a login. That gap is what stops a half-finished enrolment from locking
 * someone out or, worse, appearing to protect an account that it does not.
 */
final class BeginTwoFactorEnrolment
{
    public function __invoke(AdminUser $admin): TwoFactorEnrolment
    {
        $secret = Totp::generateSecret();

        $admin->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_enrolled_at' => Carbon::now(),
            'two_factor_confirmed_at' => null,
        ])->save();

        return new TwoFactorEnrolment(
            secret: $secret,
            provisioningUri: Totp::provisioningUri(
                secret: $secret,
                account: $admin->email,
                issuer: (string) config('veritas.identity.display_name'),
            ),
        );
    }
}
