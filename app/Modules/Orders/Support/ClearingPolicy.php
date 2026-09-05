<?php

declare(strict_types=1);

namespace App\Modules\Orders\Support;

use App\Modules\Orders\Models\SellerOrder;
use App\Modules\Sellers\Models\SellerAccount;
use Illuminate\Support\Carbon;

/**
 * How long a delivered order's earnings clear before a seller may spend
 * them — resolved in one place, never as a literal.
 *
 * The hierarchy is the one the platform already had: the seller's own
 * override if they have one, otherwise the platform setting, otherwise the
 * seven-day default in config. `Carbon::now()->addDays(7)` scattered
 * through the code would mean changing the platform's terms required a
 * deploy and a search, and would make a per-seller arrangement impossible
 * to honour.
 *
 * The clock starts at DELIVERY, not at payment. The money was recorded
 * when the payment was verified and has sat pending ever since; what
 * delivery adds is the reason to believe the customer got what they paid
 * for. Starting it at payment would let a seller be paid for goods still
 * in their own warehouse.
 */
final class ClearingPolicy
{
    /** Days this seller's earnings clear for. */
    public function daysFor(SellerAccount $seller): int
    {
        return $seller->clearingPeriodDays();
    }

    /**
     * When a delivered seller order's earnings become withdrawable.
     *
     * Computed from the delivery timestamp rather than from `now()`, so a
     * delivery recorded late — an admin correcting the record a day
     * afterwards — clears from when the parcel actually arrived, not from
     * when somebody got round to saying so.
     */
    public function availableAt(SellerOrder $sellerOrder, ?Carbon $deliveredAt = null): Carbon
    {
        $delivered = $deliveredAt ?? $sellerOrder->delivered_at ?? Carbon::now();

        return $delivered->copy()->addDays($this->daysForSellerOrder($sellerOrder));
    }

    public function daysForSellerOrder(SellerOrder $sellerOrder): int
    {
        /** @var SellerAccount|null $seller */
        $seller = SellerAccount::query()->find($sellerOrder->seller_account_id);

        return $seller === null
            ? (int) config('veritas.payouts.seller_clearing_period_days')
            : $this->daysFor($seller);
    }
}
