<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Enums;

use App\Support\HasStatusTone;
use App\Support\StatusTone;
use App\Support\StatusTransitions;

/**
 * Where a customer's review stands.
 *
 * PUBLISHED is the entry state, not an approval queue's exit. §8: a
 * verified buyer who has taken delivery and written a sentence has earned
 * the benefit of the doubt, and a marketplace that holds every honest
 * review for a human to read is a marketplace with no reviews. Moderation
 * happens after the fact and is recorded.
 *
 * There is deliberately no FLAGGED case. The brief offers it as optional,
 * and an enum case nothing can reach is worse than an absent feature — the
 * state-machine invariant would have to be relaxed to accommodate a state
 * no action produces. Flagging arrives with the action that sets it.
 */
enum ReviewStatus: string implements HasStatusTone, StatusTransitions
{
    case Published = 'published';
    case Hidden = 'hidden';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Published => [self::Hidden, self::Rejected, self::Withdrawn],
            // Hidden is reversible: it is the state for "this needs a
            // look", and looking must be able to conclude "it was fine".
            self::Hidden => [self::Published, self::Rejected, self::Withdrawn],
            /*
             * Rejected is reversible too, and only by an admin restoring
             * it. That matters because the uniqueness index counts a
             * rejected review as live: without a way back, a mistaken
             * rejection would silence that customer on that product
             * permanently.
             */
            self::Rejected => [self::Published],
            // The customer took it down. Theirs to end, and it ends here.
            self::Withdrawn => [],
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Withdrawn;
    }

    /** Whether this review is shown to the public and counted in the rating. */
    public function isPublic(): bool
    {
        return $this === self::Published;
    }

    /**
     * Whether this review still occupies the customer's one slot for this
     * product.
     *
     * Matches `product_reviews_one_live_per_customer` exactly, and an
     * invariant asserts the two agree. A withdrawn review frees the slot;
     * a rejected one does not, because letting a refused review be
     * replaced by another would make moderation a formality.
     */
    public function isLive(): bool
    {
        return $this !== self::Withdrawn;
    }

    /** Whether the customer may still edit it. */
    public function isEditableByAuthor(): bool
    {
        return in_array($this, [self::Published, self::Hidden], true);
    }

    /** A decision the customer is told about needs a reason they can read. */
    public function requiresReason(): bool
    {
        return in_array($this, [self::Hidden, self::Rejected], true);
    }

    public function tone(): StatusTone
    {
        return match ($this) {
            self::Published => StatusTone::Neutral,
            self::Hidden => StatusTone::Pending,
            self::Rejected => StatusTone::Critical,
            self::Withdrawn => StatusTone::Inactive,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Published => 'Published',
            self::Hidden => 'Hidden',
            self::Rejected => 'Rejected',
            self::Withdrawn => 'Withdrawn',
        };
    }
}
