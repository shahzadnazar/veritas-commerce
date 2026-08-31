<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Enums;

use App\Support\HasStatusTone;
use App\Support\StatusTone;
use App\Support\StatusTransitions;

enum SellerApplicationStatus: string implements HasStatusTone, StatusTransitions
{
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Suspended = 'suspended';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Submitted => [self::UnderReview, self::Approved, self::Rejected],
            self::UnderReview => [self::Approved, self::Rejected],
            // A rejected applicant corrects and re-applies against the same
            // record rather than creating a duplicate.
            self::Rejected => [self::Submitted],
            self::Approved => [self::Suspended],
            self::Suspended => [self::Approved],
        };
    }

    public function isTerminal(): bool
    {
        return false;
    }

    /** A decision that must carry a written reason, shown to the applicant verbatim. */
    public function requiresReason(): bool
    {
        return in_array($this, [self::Rejected, self::Suspended], true);
    }

    public function tone(): StatusTone
    {
        return match ($this) {
            self::Submitted, self::UnderReview => StatusTone::Pending,
            self::Approved => StatusTone::Neutral,
            self::Rejected, self::Suspended => StatusTone::Critical,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Submitted',
            self::UnderReview => 'Under review',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Suspended => 'Suspended',
        };
    }
}
