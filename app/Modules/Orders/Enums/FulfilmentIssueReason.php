<?php

declare(strict_types=1);

namespace App\Modules\Orders\Enums;

use App\Support\HasStatusTone;
use App\Support\StatusTone;

/**
 * Why a seller order cannot be fulfilled as sold.
 *
 * A short, closed list rather than free text, because the whole point is
 * that an operator can filter on it: "how often does stock turn out not to
 * exist after a sale" is a question about the marketplace, and a thousand
 * distinct sentences cannot answer it. The note beside it carries the
 * detail.
 *
 * Deliberately not a support ticket system — no threads, no assignment, no
 * SLA. It records a problem, who reported it, and who resolved it.
 */
enum FulfilmentIssueReason: string implements HasStatusTone
{
    /** Sold, then found not to be on the shelf. The expensive one. */
    case OutOfStockAfterSale = 'out_of_stock_after_sale';

    case DamagedBeforeShipment = 'damaged_before_shipment';

    case AddressProblem = 'address_problem';

    case CarrierProblem = 'carrier_problem';

    case Other = 'other';

    /** Whether the marketplace generally has to refund to resolve this. */
    public function usuallyEndsInRefund(): bool
    {
        return in_array($this, [self::OutOfStockAfterSale, self::DamagedBeforeShipment], true);
    }

    public function tone(): StatusTone
    {
        return match ($this) {
            self::OutOfStockAfterSale, self::DamagedBeforeShipment => StatusTone::Critical,
            self::AddressProblem, self::CarrierProblem => StatusTone::Pending,
            self::Other => StatusTone::Inactive,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::OutOfStockAfterSale => 'Out of stock after sale',
            self::DamagedBeforeShipment => 'Damaged before shipment',
            self::AddressProblem => 'Address problem',
            self::CarrierProblem => 'Carrier problem',
            self::Other => 'Other',
        };
    }
}
