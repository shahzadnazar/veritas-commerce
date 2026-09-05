<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Enums;

use App\Support\HasEntryStates;
use App\Support\HasStatusTone;
use App\Support\StatusTone;
use App\Support\StatusTransitions;

/**
 * Where a ledger entry sits on its way to being withdrawable.
 *
 * Pending -> Clearing -> Available, and separately Paid for the debit a
 * settled payout appends. The clearing period is configured
 * (seller_clearing_period_days), never hard-coded, and each entry carries
 * its own available_at so the rule can change without rewriting history.
 *
 * There is deliberately no `reserved_for_payout` status. M0 had one, and
 * it made a payout hold look like a stage in an entry's life; it is not.
 * A hold is a claim against part of the balance, often a part of one
 * earning, which a status column on a whole row cannot express — and
 * carrying it in both places is what makes a settlement subtract the same
 * money twice. Holds live in `payout_allocations` (M7), and this enum
 * answers only one question: has this money finished clearing.
 */
enum LedgerEntryStatus: string implements HasEntryStates, HasStatusTone, StatusTransitions
{
    case Pending = 'pending';
    case Clearing = 'clearing';
    case Available = 'available';
    case Paid = 'paid';
    case Reversed = 'reversed';

    /**
     * Where an entry can be created, as opposed to where it can move to.
     *
     * PENDING   an earning whose order has not been delivered.
     * CLEARING  an earning on a delivered order, or an adjustment credit.
     * AVAILABLE a reversal against money that had already cleared, and an
     *           adjustment debit, both of which must bite immediately.
     * PAID      the debit a settled payout appends. It is created spent
     *           and stays that way; nothing transitions into it, because
     *           the money did not pass through this state on its way
     *           anywhere — it left.
     *
     * @return array<int, self>
     */
    public static function entryStates(): array
    {
        return [self::Pending, self::Clearing, self::Available, self::Paid];
    }

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Clearing, self::Reversed],
            self::Clearing => [self::Available, self::Reversed],
            /*
             * Available money is spent by appending a payout debit, not
             * by moving the earning: the earning stays exactly as it was
             * and the debit sits beside it.
             */
            self::Available => [self::Reversed],
            self::Paid => [self::Reversed],
            self::Reversed => [],
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Reversed;
    }

    /**
     * Counts toward the settled position a payout is measured against.
     *
     * Both cases, because a payout debit is settled money too — it left.
     * Summing only `available` would leave a seller's spendable balance
     * showing the earnings they have already been paid.
     */
    public function countsAsSettled(): bool
    {
        return in_array($this, [self::Available, self::Paid], true);
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
            self::Pending, self::Clearing => StatusTone::Pending,
            self::Reversed => StatusTone::Critical,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Clearing => 'Clearing',
            self::Available => 'Available',
            self::Paid => 'Paid',
            self::Reversed => 'Reversed',
        };
    }
}
