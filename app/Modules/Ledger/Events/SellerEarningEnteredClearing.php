<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Events;

/**
 * Dispatched after the transaction commits. Scalars only.
 *
 * Carries no amount: the money is on the ledger, and an event repeating it
 * would be a second copy of a figure that must have exactly one source.
 */
final readonly class SellerEarningEnteredClearing
{
    public function __construct(
        public int $sellerAccountId,
        public int $sellerOrderId,
        public string $sellerOrderReference,
        public int $entryCount,
    ) {}
}
