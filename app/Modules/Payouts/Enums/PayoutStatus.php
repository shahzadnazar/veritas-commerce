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
            self::Approved => [self::Processing, self::Paid, self::Failed],
            self::Processing => [self::Paid, self::Failed],
            self::Failed => [self::Processing, self::Cancelled],
            self::Rejected, self::Paid, self::Cancelled => [],
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Rejected, self::Paid, self::Cancelled], true);
    }

    /** While open, the requested amount is held out of the available balance. */
    public function holdsBalance(): bool
    {
        return in_array($this, [self::Requested, self::UnderReview, self::Approved, self::Processing], true);
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
