<?php

declare(strict_types=1);

namespace App\Modules\Payouts\Enums;

use App\Support\HasStatusTone;
use App\Support\StatusTone;

/**
 * How money reaches a seller.
 *
 * Phase 1 is MANUAL throughout: a person at the platform makes the
 * transfer however the seller was set up outside the system, and records
 * the reference. The other two cases exist so that adding a rail later is
 * a new adapter rather than a new column, and so nothing in the domain has
 * to ask "is this a Stripe account" to decide what to do.
 */
enum PayoutAccountType: string implements HasStatusTone
{
    case Manual = 'manual';
    case BankTransfer = 'bank_transfer';
    case Provider = 'provider';

    /** Whether settlement is recorded by a person rather than performed by a rail. */
    public function isManuallySettled(): bool
    {
        return $this !== self::Provider;
    }

    public function tone(): StatusTone
    {
        return match ($this) {
            self::Manual => StatusTone::Pending,
            self::BankTransfer, self::Provider => StatusTone::Neutral,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual transfer',
            self::BankTransfer => 'Bank transfer',
            self::Provider => 'Payment provider',
        };
    }
}
