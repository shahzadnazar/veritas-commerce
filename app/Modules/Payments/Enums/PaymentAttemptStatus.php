<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

use App\Support\HasStatusTone;
use App\Support\StatusTone;
use App\Support\StatusTransitions;

/**
 * Where one attempt to take money stands, in the platform's own words.
 *
 * Deliberately not Stripe's vocabulary. A provider's status strings are an
 * API detail that changes when the provider decides it should, and an
 * application that branches on them directly has bound its order lifecycle
 * to somebody else's release notes. One translator maps the provider's
 * state into this enum; everything downstream reads this.
 *
 * The transitions matter as much as the cases. Provider events arrive out
 * of order — a `processing` notification can land after the `succeeded`
 * one it preceded — so a terminal state must refuse to go backwards, and
 * that refusal lives here rather than in each handler that remembers to
 * check.
 */
enum PaymentAttemptStatus: string implements HasStatusTone, StatusTransitions
{
    /** Recorded, before the provider has been asked for anything. */
    case Created = 'created';

    /** The provider is waiting for the customer to supply a card. */
    case RequiresPaymentMethod = 'requires_payment_method';

    /** 3-D Secure, or another step only the customer can complete. */
    case RequiresAction = 'requires_action';

    /** With the provider, outcome not yet known. */
    case Processing = 'processing';

    /** Money captured, verified against the order. Terminal. */
    case Succeeded = 'succeeded';

    /** The provider refused it. Terminal for this attempt, not for the order. */
    case Failed = 'failed';

    /** Abandoned or cancelled before capture. Terminal. */
    case Cancelled = 'cancelled';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Created => [
                self::RequiresPaymentMethod, self::RequiresAction,
                self::Processing, self::Succeeded, self::Failed, self::Cancelled,
            ],
            self::RequiresPaymentMethod => [
                self::RequiresAction, self::Processing, self::Succeeded, self::Failed, self::Cancelled,
            ],
            self::RequiresAction => [
                self::RequiresPaymentMethod, self::Processing, self::Succeeded, self::Failed, self::Cancelled,
            ],
            self::Processing => [
                // Back to requiring a method is legitimate: a 3-D Secure
                // failure returns the intent to the customer.
                self::RequiresPaymentMethod, self::RequiresAction,
                self::Succeeded, self::Failed, self::Cancelled,
            ],
            // Nothing leaves a decided attempt. A stale `processing` event
            // arriving after success is discarded here, not argued with.
            self::Succeeded, self::Failed, self::Cancelled => [],
        };
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /** Whether this attempt is still expecting money. */
    public function isOpen(): bool
    {
        return ! $this->isTerminal();
    }

    /**
     * Whether the customer can still act on this attempt in the browser.
     *
     * A failed attempt is terminal as a record but does not close the
     * order: §20 is explicit that a declined card must leave the customer
     * able to try another one while their stock is still held.
     */
    public function customerMayRetry(): bool
    {
        return $this === self::Failed || $this === self::Cancelled;
    }

    public function tone(): StatusTone
    {
        return match ($this) {
            self::Succeeded => StatusTone::Neutral,
            self::Created, self::RequiresPaymentMethod, self::RequiresAction, self::Processing => StatusTone::Pending,
            self::Failed => StatusTone::Critical,
            self::Cancelled => StatusTone::Inactive,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Started',
            self::RequiresPaymentMethod => 'Awaiting payment details',
            self::RequiresAction => 'Awaiting confirmation',
            self::Processing => 'Processing',
            self::Succeeded => 'Paid',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }
}
