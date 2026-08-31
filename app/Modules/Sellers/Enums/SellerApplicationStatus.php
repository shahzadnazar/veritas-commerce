<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Enums;

use App\Support\HasStatusTone;
use App\Support\StatusTone;
use App\Support\StatusTransitions;

enum SellerApplicationStatus: string implements HasStatusTone, StatusTransitions
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case ChangesRequested = 'changes_requested';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Submitted],
            self::Submitted => [self::UnderReview, self::ChangesRequested, self::Approved, self::Rejected],
            self::UnderReview => [self::ChangesRequested, self::Approved, self::Rejected],
            // "Fix one field and resend" is its own state. Overloading
            // rejection for it would tell an applicant they failed when
            // they have not, and would make the two outcomes
            // indistinguishable in every report afterwards.
            self::ChangesRequested => [self::Submitted, self::Rejected],
            // Rejection is final for this record; a fresh attempt is a new
            // application, so the decision history stays truthful.
            self::Approved, self::Rejected => [],
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Approved, self::Rejected], true);
    }

    /** States a reviewer may act on. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Submitted, self::UnderReview, self::ChangesRequested], true);
    }

    /** The applicant can edit and resend while in these states. */
    public function isEditableByApplicant(): bool
    {
        return in_array($this, [self::Draft, self::ChangesRequested], true);
    }

    /** A decision that must carry a written reason, shown to the applicant verbatim. */
    public function requiresReason(): bool
    {
        return in_array($this, [self::Rejected, self::ChangesRequested], true);
    }

    public function tone(): StatusTone
    {
        return match ($this) {
            self::Submitted, self::UnderReview, self::ChangesRequested => StatusTone::Pending,
            self::Approved => StatusTone::Neutral,
            self::Rejected => StatusTone::Critical,
            self::Draft => StatusTone::Inactive,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Submitted',
            self::UnderReview => 'Under review',
            self::ChangesRequested => 'Changes requested',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
        };
    }
}
