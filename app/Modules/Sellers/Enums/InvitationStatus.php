<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Enums;

use App\Support\HasStatusTone;
use App\Support\StatusTone;
use App\Support\StatusTransitions;

enum InvitationStatus: string implements HasStatusTone, StatusTransitions
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Revoked = 'revoked';
    case Expired = 'expired';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Accepted, self::Revoked, self::Expired],
            self::Accepted, self::Revoked, self::Expired => [],
        };
    }

    public function isTerminal(): bool
    {
        return $this !== self::Pending;
    }

    /** Only a live invitation can be accepted. */
    public function isRedeemable(): bool
    {
        return $this === self::Pending;
    }

    public function tone(): StatusTone
    {
        return match ($this) {
            self::Accepted => StatusTone::Neutral,
            self::Pending => StatusTone::Pending,
            self::Revoked => StatusTone::Critical,
            self::Expired => StatusTone::Inactive,
        };
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
