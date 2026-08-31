<?php

declare(strict_types=1);

namespace App\Modules\Identity\Data;

/**
 * Plaintext recovery codes, shown once at generation.
 *
 * Only hashes reach the database, so this object is the only chance the
 * administrator has to record them.
 */
final readonly class RecoveryCodes
{
    /** @param  array<int, string>  $codes */
    public function __construct(public array $codes) {}
}
