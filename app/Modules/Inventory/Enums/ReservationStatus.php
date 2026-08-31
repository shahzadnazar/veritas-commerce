<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Enums;

use App\Support\HasStatusTone;
use App\Support\StatusTone;
use App\Support\StatusTransitions;

/**
 * A reservation holds stock without changing the physical count.
 *
 * Held -> Consumed when payment captures and the sale is written.
 * Held -> Released when checkout fails, expires or is cancelled.
 */
enum ReservationStatus: string implements HasStatusTone, StatusTransitions
{
    case Held = 'held';
    case Consumed = 'consumed';
    case Released = 'released';
    case Expired = 'expired';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Held => [self::Consumed, self::Released, self::Expired],
            self::Consumed, self::Released, self::Expired => [],
        };
    }

    public function isTerminal(): bool
    {
        return $this !== self::Held;
    }

    /** Only a live hold subtracts from available stock. */
    public function reducesAvailable(): bool
    {
        return $this === self::Held;
    }

    public function tone(): StatusTone
    {
        return match ($this) {
            self::Consumed => StatusTone::Neutral,
            self::Held => StatusTone::Pending,
            self::Expired => StatusTone::Critical,
            self::Released => StatusTone::Inactive,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Held => 'Held',
            self::Consumed => 'Consumed',
            self::Released => 'Released',
            self::Expired => 'Expired',
        };
    }
}
