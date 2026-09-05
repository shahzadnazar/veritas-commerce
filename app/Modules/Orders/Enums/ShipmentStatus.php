<?php

declare(strict_types=1);

namespace App\Modules\Orders\Enums;

use App\Support\HasStatusTone;
use App\Support\StatusTone;
use App\Support\StatusTransitions;

/**
 * Where one parcel is.
 *
 * Separate from the seller order's state because they answer different
 * questions: a seller order with two parcels, one delivered and one in
 * transit, has no single shipment status — and a single overloaded column
 * would have to lie about one of them.
 *
 * `InTransit` and `Delivered` are recorded by an authorised person, not by
 * a courier: Phase 1 has no carrier integration, and a status that claims
 * to be carrier-verified when a seller typed it is worse than one that
 * says who typed it. A future ShippingProvider can become the authority
 * for these transitions without the states changing.
 */
enum ShipmentStatus: string implements HasStatusTone, StatusTransitions
{
    /** Being assembled. Items may still be added or removed. */
    case Draft = 'draft';

    /** Packed and waiting for the carrier. Contents are fixed. */
    case Ready = 'ready';

    /** Handed over. This is the transition the customer is told about. */
    case Shipped = 'shipped';

    /** Optional intermediate, recorded manually where a seller tracks it. */
    case InTransit = 'in_transit';

    /** Arrived. This is what starts the seller's earnings clearing. */
    case Delivered = 'delivered';

    /** Something went wrong in transit and a person needs to look. */
    case Exception = 'exception';

    /** Abandoned before dispatch; its items return to the fulfilable pool. */
    case Cancelled = 'cancelled';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Ready, self::Shipped, self::Cancelled],
            self::Ready => [self::Shipped, self::Draft, self::Cancelled],
            self::Shipped => [self::InTransit, self::Delivered, self::Exception],
            self::InTransit => [self::Delivered, self::Exception],
            // An exception can still end in delivery — a parcel returned to
            // the depot and re-attempted is the ordinary case.
            self::Exception => [self::InTransit, self::Delivered, self::Cancelled],
            // Nothing leaves a delivered or cancelled parcel. A correction
            // is an explicit, permissioned override with a reason, not a
            // transition anybody can make.
            self::Delivered, self::Cancelled => [],
        };
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /** Whether this parcel's items still count as on their way. */
    public function holdsAllocation(): bool
    {
        return $this !== self::Cancelled;
    }

    /** Whether the goods have left the seller. */
    public function hasLeft(): bool
    {
        return in_array($this, [self::Shipped, self::InTransit, self::Delivered, self::Exception], true);
    }

    /** Contents may only change while the parcel is still being made up. */
    public function contentsAreMutable(): bool
    {
        return in_array($this, [self::Draft, self::Ready], true);
    }

    public function tone(): StatusTone
    {
        return match ($this) {
            self::Delivered => StatusTone::Neutral,
            self::Draft, self::Ready, self::Shipped, self::InTransit => StatusTone::Pending,
            self::Exception => StatusTone::Critical,
            self::Cancelled => StatusTone::Inactive,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Ready => 'Ready to ship',
            self::Shipped => 'Shipped',
            self::InTransit => 'In transit',
            self::Delivered => 'Delivered',
            self::Exception => 'Delivery problem',
            self::Cancelled => 'Cancelled',
        };
    }
}
