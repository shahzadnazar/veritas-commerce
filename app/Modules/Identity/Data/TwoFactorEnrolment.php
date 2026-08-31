<?php

declare(strict_types=1);

namespace App\Modules\Identity\Data;

/**
 * The one moment the secret is visible.
 *
 * Returned to the enrolling administrator and never persisted in a
 * response, a log or an audit record.
 */
final readonly class TwoFactorEnrolment
{
    public function __construct(
        public string $secret,
        public string $provisioningUri,
    ) {}
}
