<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

use App\Support\HasStatusTone;
use App\Support\StatusTone;
use App\Support\StatusTransitions;

enum PaymentStatus: string implements HasStatusTone, StatusTransitions
{
    case Pending = 'pending';
    case Authorized = 'authorized';
    case Captured = 'captured';
    case Failed = 'failed';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Authorized, self::Captured, self::Failed],
            self::Authorized => [self::Captured, self::Failed],
            self::Captured => [self::PartiallyRefunded, self::Refunded],
            self::PartiallyRefunded => [self::Refunded],
            self::Failed, self::Refunded => [],
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Failed, self::Refunded], true);
    }

    public function tone(): StatusTone
    {
        return match ($this) {
            self::Captured, self::Authorized => StatusTone::Neutral,
            self::Pending => StatusTone::Pending,
            self::Failed, self::Refunded, self::PartiallyRefunded => StatusTone::Critical,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Authorized => 'Authorized',
            self::Captured => 'Captured',
            self::Failed => 'Failed',
            self::Refunded => 'Refunded',
            self::PartiallyRefunded => 'Partially refunded',
        };
    }
}
