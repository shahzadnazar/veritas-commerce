<?php

declare(strict_types=1);

namespace App\Modules\Orders\Enums;

use App\Support\HasStatusTone;
use App\Support\StatusTone;
use App\Support\StatusTransitions;

/**
 * Fulfilment state of one seller's slice of a marketplace order.
 *
 * The parent marketplace order derives its own state from these; a seller
 * only ever advances their own sub-order.
 */
enum SellerOrderStatus: string implements HasStatusTone, StatusTransitions
{
    case PendingPayment = 'pending_payment';
    case Paid = 'paid';
    case Confirmed = 'confirmed';
    case Processing = 'processing';
    case Packed = 'packed';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';
    case Disputed = 'disputed';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::PendingPayment => [self::Paid, self::Cancelled],
            self::Paid => [self::Confirmed, self::Cancelled, self::Refunded, self::Disputed],
            self::Confirmed => [self::Processing, self::Cancelled, self::Refunded, self::Disputed],
            self::Processing => [self::Packed, self::Cancelled, self::Refunded, self::Disputed],
            // Past packed the customer can no longer cancel unilaterally.
            self::Packed => [self::Shipped, self::Refunded, self::Disputed],
            self::Shipped => [self::Delivered, self::PartiallyRefunded, self::Refunded, self::Disputed],
            self::Delivered => [self::Completed, self::PartiallyRefunded, self::Refunded, self::Disputed],
            self::Completed => [self::PartiallyRefunded, self::Refunded, self::Disputed],
            self::PartiallyRefunded => [self::Refunded, self::Completed, self::Disputed],
            self::Disputed => [self::Refunded, self::PartiallyRefunded, self::Completed],
            self::Cancelled, self::Refunded => [],
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Cancelled, self::Refunded], true);
    }

    /** Shipping requires a carrier and a tracking number — enforced server-side. */
    public function requiresTracking(): bool
    {
        return $this === self::Shipped;
    }

    /** A customer may cancel until the seller marks the order packed. */
    public function isCustomerCancellable(): bool
    {
        return in_array($this, [self::Paid, self::Confirmed, self::Processing], true);
    }

    /** States in which stock is still held or consumed on the seller's behalf. */
    public function holdsInventory(): bool
    {
        return ! in_array($this, [self::Cancelled, self::Refunded], true);
    }

    /** Earning is posted to the ledger when the sub-order reaches this state. */
    public function postsEarning(): bool
    {
        return $this === self::Delivered;
    }

    public function tone(): StatusTone
    {
        return match ($this) {
            self::Paid, self::Delivered, self::Completed => StatusTone::Neutral,
            self::PendingPayment, self::Confirmed, self::Processing,
            self::Packed, self::Shipped => StatusTone::Pending,
            self::Refunded, self::PartiallyRefunded, self::Disputed => StatusTone::Critical,
            self::Cancelled => StatusTone::Inactive,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::PendingPayment => 'Pending payment',
            self::Paid => 'Paid',
            self::Confirmed => 'Confirmed',
            self::Processing => 'Processing',
            self::Packed => 'Packed',
            self::Shipped => 'Shipped',
            self::Delivered => 'Delivered',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::PartiallyRefunded => 'Partially refunded',
            self::Refunded => 'Refunded',
            self::Disputed => 'Disputed',
        };
    }
}
