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
    case RestockReceived = 'restock_received';
    case CountCorrection = 'count_correction';
    case Damaged = 'damaged';
    case ReturnedToSupplier = 'returned_to_supplier';
    case ManualEdit = 'manual_edit';
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
            self::ReturnedToSupplier,
        ], true);
    }

    public function actorIsSystem(): bool
    {
        return in_array($this, [self::SaleCompleted, self::OrderCancelled, self::RefundRestock], true);
    }

    public function tone(): StatusTone
    {
        return match ($this) {
            self::OpeningStock, self::RestockReceived, self::RefundRestock, self::OrderCancelled => StatusTone::Neutral,
            self::CountCorrection, self::ManualEdit => StatusTone::Pending,
            self::Damaged, self::ReturnedToSupplier => StatusTone::Critical,
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
            self::ReturnedToSupplier => 'Returned to supplier',
            self::ManualEdit => 'Manual edit',
            self::SaleCompleted => 'Sale completed',
            self::OrderCancelled => 'Order cancelled',
            self::RefundRestock => 'Refund restock',
        };
    }
}
