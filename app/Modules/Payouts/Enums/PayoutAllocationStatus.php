<?php

declare(strict_types=1);

namespace App\Modules\Payouts\Enums;

use App\Support\HasStatusTone;
use App\Support\StatusTone;

/**
 * What a slice of a seller's earnings is doing on behalf of a payout.
 *
 * Only HELD takes money out of the withdrawable balance. The other two are
 * history: SETTLED says the payout was paid and the ledger debit has taken
 * over, RELEASED says the request ended without paying and the money went
 * back. Keeping the row in all three cases is what makes "why was $200 of
 * my balance unavailable last Tuesday" an answerable question.
 */
enum PayoutAllocationStatus: string implements HasStatusTone
{
    case Held = 'held';
    case Settled = 'settled';
    case Released = 'released';

    /** Whether this allocation still reserves money. */
    public function reserves(): bool
    {
        return $this === self::Held;
    }

    public function isTerminal(): bool
    {
        return $this !== self::Held;
    }

    public function tone(): StatusTone
    {
        return match ($this) {
            self::Held => StatusTone::Pending,
            self::Settled => StatusTone::Neutral,
            self::Released => StatusTone::Inactive,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Held => 'Held',
            self::Settled => 'Settled',
            self::Released => 'Released',
        };
    }
}
