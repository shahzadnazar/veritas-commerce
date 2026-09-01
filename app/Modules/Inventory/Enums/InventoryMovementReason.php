<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Enums;

use App\Support\HasStatusTone;
use App\Support\StatusTone;

/**
 * Why stock moved. Required on every movement — there is no unexplained change.
 */
enum InventoryMovementReason: string implements HasStatusTone
{
    case OpeningStock = 'opening_stock';

    /*
     * Seller-chosen reasons for a manual correction.
     *
     * There is no MANUAL_INCREASE / MANUAL_DECREASE pair: direction is the
     * sign of the movement, and a row that says only "manual increase"
     * answers none of the questions an audit asks. "Received 20" and
     * "wrote off 3 as damaged" are both manual and mean entirely
     * different things.
     */
    case RestockReceived = 'restock_received';
    case CountCorrection = 'count_correction';
    case Damaged = 'damaged';
    case Lost = 'lost';
    case ReturnedToSupplier = 'returned_to_supplier';
    case ManualEdit = 'manual_edit';
    case Other = 'other';

    /** A platform operator correcting stock, which is its own event. */
    case AdminAdjustment = 'admin_adjustment';

    /*
     * Reservation movements.
     *
     * These change `reserved` and leave `on_hand` alone: the units have not
     * gone anywhere, they are just spoken for. They are movements all the
     * same, because "why did available drop when nothing sold" is a
     * question the ledger has to be able to answer.
     */
    case OrderReservation = 'order_reservation';
    case ReservationRelease = 'reservation_release';
    case ReservationExpired = 'reservation_expired';

    /** The sale itself: reserved and on_hand fall together. */
    case SaleCompleted = 'sale_completed';
    case OrderCancelled = 'order_cancelled';
    case RefundRestock = 'refund_restock';

    /** Reasons a seller may choose by hand; the rest are written by the system. */
    public function isSellerSelectable(): bool
    {
        return in_array($this, [
            self::RestockReceived,
            self::CountCorrection,
            self::Damaged,
            self::Lost,
            self::ReturnedToSupplier,
            self::Other,
        ], true);
    }

    public function actorIsSystem(): bool
    {
        return in_array($this, [
            self::SaleCompleted,
            self::OrderCancelled,
            self::RefundRestock,
            self::OrderReservation,
            self::ReservationRelease,
            self::ReservationExpired,
        ], true);
    }

    /** Whether this reason moves the reserved column rather than on_hand. */
    public function isReservationMovement(): bool
    {
        return in_array($this, [
            self::OrderReservation,
            self::ReservationRelease,
            self::ReservationExpired,
        ], true);
    }

    /**
     * Whether a written note is required alongside the reason code.
     *
     * "Other" explains nothing on its own, so it has to be accompanied by
     * words. Every other reason is self-describing.
     */
    public function requiresNote(): bool
    {
        return $this === self::Other;
    }

    public function tone(): StatusTone
    {
        return match ($this) {
            self::OpeningStock, self::RestockReceived, self::RefundRestock,
            self::OrderCancelled, self::ReservationRelease => StatusTone::Neutral,
            self::CountCorrection, self::ManualEdit, self::Other,
            self::OrderReservation, self::ReservationExpired => StatusTone::Pending,
            self::Damaged, self::Lost, self::ReturnedToSupplier,
            self::AdminAdjustment => StatusTone::Critical,
            self::SaleCompleted => StatusTone::Inactive,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::OpeningStock => 'Opening stock',
            self::RestockReceived => 'Restock received',
            self::CountCorrection => 'Count correction',
            self::Damaged => 'Damaged',
            self::Lost => 'Lost',
            self::ReturnedToSupplier => 'Returned to supplier',
            self::ManualEdit => 'Manual edit',
            self::Other => 'Other',
            self::AdminAdjustment => 'Platform adjustment',
            self::OrderReservation => 'Reserved for an order',
            self::ReservationRelease => 'Reservation released',
            self::ReservationExpired => 'Reservation expired',
            self::SaleCompleted => 'Sale completed',
            self::OrderCancelled => 'Order cancelled',
            self::RefundRestock => 'Refund restock',
        };
    }
}
