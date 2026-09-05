<?php

declare(strict_types=1);

namespace App\Modules\Payouts\Enums;

use App\Support\HasStatusTone;
use App\Support\StatusTone;

/**
 * One attempt to move money out to a seller.
 *
 * A failed attempt keeps its row. §66: retrying a settlement must not
 * overwrite the reason the last one failed, because that reason is the
 * only evidence the money was already tried once.
 */
enum SettlementAttemptStatus: string implements HasStatusTone
{
    case Initiated = 'initiated';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return $this !== self::Initiated;
    }

    public function tone(): StatusTone
    {
        return match ($this) {
            self::Succeeded => StatusTone::Neutral,
            self::Initiated => StatusTone::Pending,
            self::Failed => StatusTone::Critical,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Initiated => 'Initiated',
            self::Succeeded => 'Succeeded',
            self::Failed => 'Failed',
        };
    }
}
