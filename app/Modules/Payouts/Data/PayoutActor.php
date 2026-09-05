<?php

declare(strict_types=1);

namespace App\Modules\Payouts\Data;

/**
 * Who did something to a payout.
 *
 * Three kinds, and they are not interchangeable: a seller cancelling their
 * own request, a member of finance approving it, and the system recording
 * a scheduled transition are different acts, and a history row that only
 * said "id 4" could not tell them apart.
 *
 * The label is copied in rather than joined to, for the same reason the
 * destination is: staff leave and names change, and a decision made in
 * March should still say who made it.
 */
final readonly class PayoutActor
{
    private function __construct(
        public string $type,
        public ?int $id,
        public ?string $label,
    ) {}

    public static function seller(?int $userId, ?string $label = null): self
    {
        return new self('seller', $userId, $label);
    }

    public static function admin(?int $adminId, ?string $label = null): self
    {
        return new self('admin', $adminId, $label);
    }

    public static function system(string $label = 'Veritas'): self
    {
        return new self('system', null, $label);
    }

    public function isAdmin(): bool
    {
        return $this->type === 'admin';
    }
}
