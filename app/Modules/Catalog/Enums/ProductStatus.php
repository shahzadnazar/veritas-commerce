<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Enums;

use App\Support\HasStatusTone;
use App\Support\StatusTone;
use App\Support\StatusTransitions;

/**
 * Where a canonical product stands in moderation.
 *
 * Distinct from an offer's status on purpose: a published product may
 * carry a suspended offer, and a suspended product hides every offer
 * against it. Conflating the two would make "why can nobody buy this"
 * unanswerable.
 *
 * `changes_requested` is a first-class state, not a rejection with softer
 * wording. Telling a seller their product was rejected when one field
 * needs correcting is both untrue and unrecoverable in the reporting
 * afterwards.
 */
enum ProductStatus: string implements HasStatusTone, StatusTransitions
{
    /** Being written; only its proposer can see it. */
    case Draft = 'draft';

    /** Submitted, waiting for a moderator. */
    case PendingReview = 'pending_review';

    /** A moderator has asked for a correction; the proposer can edit. */
    case ChangesRequested = 'changes_requested';

    /** Accepted into the catalogue but not yet on the storefront. */
    case Approved = 'approved';

    /** Live: the public page resolves and eligible offers show. */
    case Published = 'published';

    /** Refused. Kept, not deleted — the proposal is part of the record. */
    case Rejected = 'rejected';

    /** Pulled from sale by the platform. Offers against it stop showing. */
    case Suspended = 'suspended';

    /** Retired. Historical orders keep working; nothing new lists here. */
    case Archived = 'archived';

    /** @return array<int, self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::PendingReview, self::Archived],
            self::PendingReview => [self::Approved, self::Rejected, self::ChangesRequested],
            self::ChangesRequested => [self::PendingReview, self::Archived],
            self::Approved => [self::Published, self::Suspended, self::Archived],
            self::Published => [self::Suspended, self::Archived],
            self::Rejected => [self::Draft, self::Archived],
            self::Suspended => [self::Published, self::Archived],
            self::Archived => [],
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Archived;
    }

    /** Whether a decision in this state needs a written reason. */
    public function requiresReason(): bool
    {
        return in_array($this, [self::Rejected, self::ChangesRequested, self::Suspended], true);
    }

    /** Whether the public catalogue shows this product at all. */
    public function isPublic(): bool
    {
        return $this === self::Published;
    }

    /** Whether a seller may still list an offer against it. */
    public function acceptsOffers(): bool
    {
        return in_array($this, [self::Approved, self::Published], true);
    }

    /** Whether the proposing seller may still edit the proposal. */
    public function isEditableByProposer(): bool
    {
        return in_array($this, [self::Draft, self::ChangesRequested], true);
    }

    /** Whether a moderator is expected to act on it. */
    public function awaitsModeration(): bool
    {
        return $this === self::PendingReview;
    }

    public function tone(): StatusTone
    {
        return match ($this) {
            self::Published, self::Approved => StatusTone::Neutral,
            self::PendingReview, self::ChangesRequested => StatusTone::Pending,
            self::Rejected, self::Suspended => StatusTone::Critical,
            self::Draft, self::Archived => StatusTone::Inactive,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PendingReview => 'Pending review',
            self::ChangesRequested => 'Changes requested',
            self::Approved => 'Approved',
            self::Published => 'Published',
            self::Rejected => 'Rejected',
            self::Suspended => 'Suspended',
            self::Archived => 'Archived',
        };
    }
}
