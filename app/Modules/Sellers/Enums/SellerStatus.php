<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Enums;

use App\Support\HasStatusTone;
use App\Support\StatusTone;
use App\Support\StatusTransitions;

enum SellerStatus: string implements HasStatusTone, StatusTransitions
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Suspended = 'suspended';
    case Closed = 'closed';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Approved, self::Closed],
            self::Approved => [self::Suspended, self::Closed],
            self::Suspended => [self::Approved, self::Closed],
            self::Closed => [],
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Closed;
    }

    /** Listings visible, new orders accepted. */
    public function canSell(): bool
    {
        return $this === self::Approved;
    }

    /**
     * Suspension freezes the balance against payout and hides listings, but
     * never cancels open orders — the seller must still fulfil them.
     */
    public function canRequestPayout(): bool
    {
        return $this === self::Approved;
    }

    public function requiresReason(): bool
    {
        return in_array($this, [self::Suspended, self::Closed], true);
    }

    public function tone(): StatusTone
    {
        return match ($this) {
            self::Approved => StatusTone::Neutral,
            self::Pending => StatusTone::Pending,
            self::Suspended => StatusTone::Critical,
            self::Closed => StatusTone::Inactive,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Suspended => 'Suspended',
            self::Closed => 'Closed',
        };
    }
}
