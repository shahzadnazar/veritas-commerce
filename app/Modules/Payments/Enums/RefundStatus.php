<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

use App\Support\HasStatusTone;
use App\Support\StatusTone;
use App\Support\StatusTransitions;

/**
 * Where a refund stands.
 *
 * The distinction that matters is between "we asked" and "the money left".
 * §44: financial reversals are posted when the provider says the refund
 * succeeded, not when an admin pressed the button — a refund that fails at
 * the provider after we had already reversed the seller's earning would
 * leave the seller short of money that never went anywhere.
 */
enum RefundStatus: string implements HasStatusTone, StatusTransitions
{
    case Requested = 'requested';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Requested => [self::Processing, self::Succeeded, self::Failed],
            self::Processing => [self::Succeeded, self::Failed],
            self::Succeeded, self::Failed => [],
        };
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /** Whether this refund's amount counts against the refundable balance. */
    public function holdsBalance(): bool
    {
        return $this !== self::Failed;
    }

    public function tone(): StatusTone
    {
        return match ($this) {
            self::Succeeded => StatusTone::Critical,
            self::Requested, self::Processing => StatusTone::Pending,
            self::Failed => StatusTone::Inactive,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Requested',
            self::Processing => 'Processing',
            self::Succeeded => 'Refunded',
            self::Failed => 'Refund failed',
        };
    }
}
