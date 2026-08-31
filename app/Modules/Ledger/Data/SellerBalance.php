<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Data;

use App\Support\Money;

/**
 * A seller's balance as three figures, which is what the portal shows.
 *
 * Clearing  — earned, not yet withdrawable (Decision 5's 7-day window)
 * Available — withdrawable now, less anything an open request is holding
 * Held      — the amount of the seller's one open payout request
 */
final readonly class SellerBalance
{
    public function __construct(
        public Money $clearing,
        public Money $available,
        public Money $held,
    ) {}

    /** Everything the seller is owed, whatever stage it is at. */
    public function total(): Money
    {
        return $this->clearing->plus($this->available)->plus($this->held);
    }

    public function canRequest(int $amountMinor): bool
    {
        return $amountMinor > 0 && $amountMinor <= $this->available->minor;
    }
}
