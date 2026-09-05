<?php

declare(strict_types=1);

namespace App\Modules\Payouts\Enums;

use App\Support\HasStatusTone;
use App\Support\StatusTone;
use App\Support\StatusTransitions;

enum PayoutStatus: string implements HasStatusTone, StatusTransitions
{
    case Requested = 'requested';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Processing = 'processing';
    case Paid = 'paid';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Requested => [self::UnderReview, self::Approved, self::Rejected, self::Cancelled],
            self::UnderReview => [self::Approved, self::Rejected],
            /*
             * Approved is authorised, not sent. Rejection and
             * cancellation stay open from here on purpose: an approval
             * that turns out to be wrong — a refund landed, the
             * destination was queried — needs an exit, and without one the
             * seller's money would be held against a payout nobody is
             * ever going to make.
             */
            self::Approved => [
                self::Processing, self::Paid, self::Failed,
                self::Rejected, self::Cancelled,
            ],
            self::Processing => [self::Paid, self::Failed],
            // A failed settlement can be tried again, or ended. Ending it
            // is what releases the money — see retainsReservation().
            self::Failed => [self::Processing, self::Cancelled, self::Rejected],
            self::Rejected, self::Paid, self::Cancelled => [],
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Rejected, self::Paid, self::Cancelled], true);
    }

    /**
     * While open, the request's allocations hold money out of withdrawable.
     *
     * These four are also exactly the statuses in the database's partial
     * unique index (`payout_requests_one_open_per_seller`), which is what
     * actually enforces one open request per seller. The two lists must
     * agree; the invariant suite asserts that they do, because a status
     * added here and forgotten there would silently let a seller open two.
     */
    public function holdsBalance(): bool
    {
        return in_array($this, [self::Requested, self::UnderReview, self::Approved, self::Processing], true);
    }

    /** @return array<int, string> */
    public static function openValues(): array
    {
        return array_values(array_map(
            static fn (self $status): string => $status->value,
            array_filter(self::cases(), static fn (self $status): bool => $status->holdsBalance()),
        ));
    }

    /**
     * Whether finance may still record a settlement against this request.
     *
     * Approved and Processing only. A rejected or cancelled request has
     * released its money, and paying one out would send money the seller's
     * balance no longer holds.
     */
    public function isSettleable(): bool
    {
        return in_array($this, [self::Approved, self::Processing], true);
    }

    /**
     * Whether a failed settlement leaves the money still reserved.
     *
     * §30, decided explicitly: FAILED keeps the reservation. A manual
     * transfer that bounced is retried far more often than it is
     * abandoned, and releasing the money in between would let the seller
     * request it again while finance is still chasing the first attempt —
     * which is how a seller gets paid twice. Finance ends it deliberately
     * by rejecting or cancelling, and that is what releases the hold.
     */
    public function retainsReservation(): bool
    {
        return $this === self::Failed;
    }

    /** Rejection is shown to the seller verbatim, so a reason is mandatory. */
    public function requiresReason(): bool
    {
        return in_array($this, [self::Rejected, self::Failed], true);
    }

    /** A request the seller may still withdraw themselves. */
    public function isCancellableBySeller(): bool
    {
        return $this === self::Requested;
    }

    public function tone(): StatusTone
    {
        return match ($this) {
            self::Approved, self::Paid => StatusTone::Neutral,
            self::Requested, self::UnderReview, self::Processing => StatusTone::Pending,
            self::Rejected, self::Failed => StatusTone::Critical,
            self::Cancelled => StatusTone::Inactive,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Requested',
            self::UnderReview => 'Under review',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Processing => 'Processing',
            self::Paid => 'Paid',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }
}
