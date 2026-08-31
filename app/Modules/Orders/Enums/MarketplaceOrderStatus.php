<?php

declare(strict_types=1);

namespace App\Modules\Orders\Enums;

use App\Support\HasStatusTone;
use App\Support\StatusTone;
use App\Support\StatusTransitions;

/**
 * The customer's view of an order that may span several sellers.
 *
 * Derived from the sub-orders: PartiallyShipped exists because a customer
 * with three sellers genuinely has a partly-shipped order, and saying
 * "Processing" would be a lie.
 */
enum MarketplaceOrderStatus: string implements HasStatusTone, StatusTransitions
{
    case PendingPayment = 'pending_payment';
    case Paid = 'paid';
    case Processing = 'processing';
    case PartiallyShipped = 'partially_shipped';
    case Shipped = 'shipped';
    case PartiallyDelivered = 'partially_delivered';
    case Delivered = 'delivered';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::PendingPayment => [self::Paid, self::Cancelled],
            self::Paid => [self::Processing, self::Cancelled, self::Refunded],
            self::Processing => [self::PartiallyShipped, self::Shipped, self::Cancelled, self::PartiallyRefunded, self::Refunded],
            self::PartiallyShipped => [self::Shipped, self::PartiallyDelivered, self::PartiallyRefunded, self::Refunded],
            self::Shipped => [self::PartiallyDelivered, self::Delivered, self::PartiallyRefunded, self::Refunded],
            self::PartiallyDelivered => [self::Delivered, self::PartiallyRefunded, self::Refunded],
            self::Delivered => [self::Completed, self::PartiallyRefunded, self::Refunded],
            self::Completed => [self::PartiallyRefunded, self::Refunded],
            self::PartiallyRefunded => [self::Refunded, self::Completed],
            self::Cancelled, self::Refunded => [],
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Cancelled, self::Refunded], true);
    }

    public function tone(): StatusTone
    {
        return match ($this) {
            self::Paid, self::Delivered, self::Completed => StatusTone::Neutral,
            self::PendingPayment, self::Processing, self::PartiallyShipped,
            self::Shipped, self::PartiallyDelivered => StatusTone::Pending,
            self::Refunded, self::PartiallyRefunded => StatusTone::Critical,
            self::Cancelled => StatusTone::Inactive,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::PendingPayment => 'Pending payment',
            self::Paid => 'Paid',
            self::Processing => 'Processing',
            self::PartiallyShipped => 'Partially shipped',
            self::Shipped => 'Shipped',
            self::PartiallyDelivered => 'Partially delivered',
            self::Delivered => 'Delivered',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::PartiallyRefunded => 'Partially refunded',
            self::Refunded => 'Refunded',
        };
    }
}
