<?php

declare(strict_types=1);

namespace App\Modules\Offers\Enums;

use App\Support\HasStatusTone;
use App\Support\StatusTone;
use App\Support\StatusTransitions;

/**
 * Moderation and visibility state of a seller's offer.
 *
 * Rejection returns the listing to the seller as a draft with the reason
 * attached rather than deleting it — nothing a seller wrote is ever lost.
 */
enum OfferStatus: string implements HasStatusTone, StatusTransitions
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case Published = 'published';
    case Rejected = 'rejected';
    case Suspended = 'suspended';
    case Archived = 'archived';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::PendingReview, self::Published, self::Archived],
            self::PendingReview => [self::Approved, self::Rejected, self::Draft],
            self::Approved => [self::Published, self::Archived],
            self::Published => [self::Draft, self::Suspended, self::Archived],
            self::Rejected => [self::Draft, self::Archived],
            self::Suspended => [self::Published, self::Draft, self::Archived],
            self::Archived => [],
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Archived;
    }

    /** Only a published offer may be bought. */
    public function isPurchasable(): bool
    {
        return $this === self::Published;
    }

    public function requiresReason(): bool
    {
        return in_array($this, [self::Rejected, self::Suspended], true);
    }

    public function tone(): StatusTone
    {
        return match ($this) {
            self::Published, self::Approved => StatusTone::Neutral,
            self::PendingReview => StatusTone::Pending,
            self::Rejected, self::Suspended => StatusTone::Critical,
            self::Draft, self::Archived => StatusTone::Inactive,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PendingReview => 'Pending review',
            self::Approved => 'Approved',
            self::Published => 'Published',
            self::Rejected => 'Rejected',
            self::Suspended => 'Suspended',
            self::Archived => 'Archived',
        };
    }
}
