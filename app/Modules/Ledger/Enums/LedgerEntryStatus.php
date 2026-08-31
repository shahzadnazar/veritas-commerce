<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Enums;

use App\Support\HasStatusTone;
use App\Support\StatusTone;
use App\Support\StatusTransitions;

/**
 * Where a ledger entry sits on its way to being withdrawable.
 *
 * Pending -> Clearing -> Available -> ReservedForPayout -> Paid
 *
 * The clearing period is configured (seller_clearing_period_days), never
 * hard-coded, and each entry carries its own available_at so the rule can
 * change without rewriting history.
 */
enum LedgerEntryStatus: string implements HasStatusTone, StatusTransitions
{
    case Pending = 'pending';
    case Clearing = 'clearing';
    case Available = 'available';
    case ReservedForPayout = 'reserved_for_payout';
    case Paid = 'paid';
    case Reversed = 'reversed';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Clearing, self::Reversed],
            self::Clearing => [self::Available, self::Reversed],
            self::Available => [self::ReservedForPayout, self::Reversed],
            self::ReservedForPayout => [self::Paid, self::Available, self::Reversed],
            self::Paid => [self::Reversed],
            self::Reversed => [],
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Reversed;
    }

    /** Counts toward the balance a seller may request a payout against. */
    public function countsAsAvailable(): bool
    {
        return $this === self::Available;
    }

    /** Earned but not yet withdrawable. */
    public function countsAsClearing(): bool
    {
        return in_array($this, [self::Pending, self::Clearing], true);
    }

    public function tone(): StatusTone
    {
        return match ($this) {
            self::Available, self::Paid => StatusTone::Neutral,
            self::Pending, self::Clearing, self::ReservedForPayout => StatusTone::Pending,
            self::Reversed => StatusTone::Critical,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Clearing => 'Clearing',
            self::Available => 'Available',
            self::ReservedForPayout => 'Reserved for payout',
            self::Paid => 'Paid',
            self::Reversed => 'Reversed',
        };
    }
}
