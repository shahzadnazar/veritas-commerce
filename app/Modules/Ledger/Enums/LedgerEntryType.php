<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Enums;

use App\Support\HasStatusTone;
use App\Support\StatusTone;

/**
 * Every movement of seller money is one of these, and every row is permanent.
 *
 * A mistake is corrected by an Adjustment referencing the original, never by
 * editing a row.
 */
enum LedgerEntryType: string implements HasStatusTone
{
    case SaleEarning = 'sale_earning';
    case Commission = 'commission';
    case RefundReversal = 'refund_reversal';
    case Adjustment = 'adjustment';
    case PayoutReservation = 'payout_reservation';
    case Payout = 'payout';
    case Reversal = 'reversal';

    /** The sign this entry type must carry. 0 means either is valid. */
    public function expectedSign(): int
    {
        return match ($this) {
            self::SaleEarning => 1,
            self::Commission, self::RefundReversal, self::PayoutReservation, self::Payout => -1,
            self::Reversal => 1,
            self::Adjustment => 0,
        };
    }

    /** Reservations are a hold, not a movement of the balance total. */
    public function isHold(): bool
    {
        return $this === self::PayoutReservation;
    }

    public function tone(): StatusTone
    {
        return match ($this) {
            self::SaleEarning, self::Reversal => StatusTone::Neutral,
            self::PayoutReservation => StatusTone::Pending,
            self::Commission, self::RefundReversal, self::Payout => StatusTone::Inactive,
            self::Adjustment => StatusTone::Critical,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::SaleEarning => 'Sale earning',
            self::Commission => 'Commission',
            self::RefundReversal => 'Refund reversal',
            self::Adjustment => 'Adjustment',
            self::PayoutReservation => 'Payout reservation',
            self::Payout => 'Payout',
            self::Reversal => 'Reversal',
        };
    }
}
