<?php

declare(strict_types=1);

namespace App\Modules\Orders\Enums;

use App\Support\HasStatusTone;
use App\Support\StatusTone;
use App\Support\StatusTransitions;

/**
 * Fulfilment state of one seller's slice of a marketplace order.
 *
 * DELIBERATELY NOT A PAYMENT STATE. `paid` is here as the entry point —
 * the moment fulfilment becomes possible — and everything after it is
 * about the physical goods. Whether money arrived, was refunded, or is
 * clearing is answered by the payment and ledger tables; an order that is
 * `shipped` and fully refunded is a real, coherent thing, and a single
 * column trying to say both would have to lie about one of them.
 *
 * The partial states exist because a seller order can ship in more than
 * one parcel. `shipped` means everything fulfilable has left; anything
 * less is `partially_shipped`, and the same for delivery. The aggregate is
 * derived centrally from shipment items rather than set by whoever pressed
 * the button — one parcel arriving is not an order arriving.
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
    case PartiallyShipped = 'partially_shipped';
    case Shipped = 'shipped';
    case PartiallyDelivered = 'partially_delivered';
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
            /*
             * Straight to packed is legitimate: a seller who confirms and
             * immediately makes up the parcel has done the processing,
             * and forcing a click that means nothing would only teach
             * them to click it without meaning it.
             */
            self::Confirmed => [self::Processing, self::Packed, self::Cancelled, self::Refunded, self::Disputed],
            self::Processing => [self::Packed, self::Cancelled, self::Refunded, self::Disputed],
            /*
             * Past packed the customer can no longer cancel unilaterally,
             * and the order may go out in one parcel or several.
             */
            self::Packed => [
                self::PartiallyShipped, self::Shipped,
                self::PartiallyRefunded, self::Refunded, self::Disputed,
            ],
            /*
             * Straight to delivered is reachable and not a mistake: a
             * refund can reduce what the seller still owes, so an order
             * that shipped two of three units is fully delivered once
             * those two arrive and the third has been refunded. Holding it
             * open waiting for a unit nobody owes anybody would strand the
             * seller's earnings forever.
             */
            self::PartiallyShipped => [
                self::Shipped, self::PartiallyDelivered, self::Delivered,
                self::PartiallyRefunded, self::Refunded, self::Disputed,
            ],
            self::Shipped => [
                self::PartiallyDelivered, self::Delivered,
                self::PartiallyRefunded, self::Refunded, self::Disputed,
            ],
            self::PartiallyDelivered => [
                self::Delivered, self::PartiallyRefunded, self::Refunded, self::Disputed,
            ],
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

    /**
     * Whether the seller may act on this order at all.
     *
     * The payment boundary, as data: everything before `paid` is a
     * purchase that has not been paid for, and a seller told to pack one
     * either ships for nothing or learns to distrust the queue.
     */
    public function isActionable(): bool
    {
        return ! in_array($this, [
            self::PendingPayment, self::Cancelled, self::Refunded,
        ], true);
    }

    /** Whether goods for this order have begun to leave the seller. */
    public function hasShipped(): bool
    {
        return in_array($this, [
            self::PartiallyShipped, self::Shipped,
            self::PartiallyDelivered, self::Delivered, self::Completed,
        ], true);
    }

    /** Whether every fulfilable unit has arrived. */
    public function isFullyDelivered(): bool
    {
        return in_array($this, [self::Delivered, self::Completed], true);
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

    /**
     * Whether reaching this state starts the seller's earnings clearing.
     *
     * Delivery, not payment: the money was recorded at payment and has sat
     * pending ever since. A partially delivered order does not start the
     * clock — Phase 1 clears a seller order as a whole (§71), which keeps
     * one date per order instead of one per parcel.
     */
    public function startsEarningsClearing(): bool
    {
        return $this === self::Delivered;
    }

    public function tone(): StatusTone
    {
        return match ($this) {
            self::Paid, self::Delivered, self::Completed => StatusTone::Neutral,
            self::PendingPayment, self::Confirmed, self::Processing,
            self::Packed, self::PartiallyShipped, self::Shipped,
            self::PartiallyDelivered => StatusTone::Pending,
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
            self::PartiallyShipped => 'Partially shipped',
            self::Shipped => 'Shipped',
            self::PartiallyDelivered => 'Partially delivered',
            self::Delivered => 'Delivered',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::PartiallyRefunded => 'Partially refunded',
            self::Refunded => 'Refunded',
            self::Disputed => 'Disputed',
        };
    }
}
