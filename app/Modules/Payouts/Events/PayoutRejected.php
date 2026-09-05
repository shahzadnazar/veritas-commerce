<?php

declare(strict_types=1);

namespace App\Modules\Payouts\Events;

/**
 * Finance refused the payout and the reservation went back.
 *
 * Dispatched after the transaction commits. Carries what a listener needs
 * to write a message, so nothing downstream has to read the ledger back —
 * and no listener ever moves money: every financial mutation is in the
 * action, before the commit that dispatches this. §81.
 */
final readonly class PayoutRejected
{
    public function __construct(
        public int $payoutRequestId,
        public string $reference,
        public int $sellerAccountId,
        public string $sellerName,
        public int $amountMinor,
        public string $currency,
        public string $reason = '',
        public ?int $decidedByAdminId = null,
    ) {}
}
