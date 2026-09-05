<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Data;

/**
 * Who acted on a review.
 *
 * A customer withdrawing their own words and a moderator hiding them are
 * different acts, and a history row that only said "id 4" could not tell
 * them apart. The label is copied in rather than joined to, so a decision
 * made in March still says who made it after they have left.
 */
final readonly class ReviewActor
{
    private function __construct(
        public string $type,
        public ?int $id,
        public ?string $label,
    ) {}

    public static function customer(?int $userId, ?string $label = null): self
    {
        return new self('customer', $userId, $label);
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
