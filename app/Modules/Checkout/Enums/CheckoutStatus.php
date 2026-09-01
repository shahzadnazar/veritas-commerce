<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Enums;

use App\Support\HasStatusTone;
use App\Support\StatusTone;

/**
 * Where one attempt to check out stands.
 *
 * An attempt is a record, not a session variable, because idempotency
 * needs somewhere durable to say "this key already produced that order".
 * Every terminal state is kept: a customer asking why their card was
 * declined, and an operator asking why stock was held for twenty minutes
 * and then released, are both answered from this table.
 */
enum CheckoutStatus: string implements HasStatusTone
{
    /** Accepted, quoted and holding stock, waiting for payment. */
    case Reserved = 'reserved';

    /** Became a marketplace order. Terminal. */
    case Completed = 'completed';

    /** Refused before any order existed — bad stock, a pulled offer. Terminal. */
    case Failed = 'failed';

    /** Its hold ran out before payment. Terminal. */
    case Expired = 'expired';

    /** Nothing more will happen to this attempt. */
    public function isDecided(): bool
    {
        return $this !== self::Reserved;
    }

    /** Whether this attempt still holds inventory. */
    public function holdsStock(): bool
    {
        return $this === self::Reserved;
    }

    public function tone(): StatusTone
    {
        return match ($this) {
            self::Reserved => StatusTone::Pending,
            self::Completed => StatusTone::Neutral,
            self::Failed => StatusTone::Critical,
            self::Expired => StatusTone::Inactive,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Reserved => 'Awaiting payment',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Expired => 'Expired',
        };
    }
}
