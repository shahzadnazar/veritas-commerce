<?php

declare(strict_types=1);

namespace App\Modules\Orders\Queries;

use App\Modules\Orders\Data\SellerOrderSummary;
use App\Modules\Orders\Models\SellerOrder;
use App\Support\Money;

/**
 * The seller portal's order queue, as data rather than models.
 *
 * The tenant scope on SellerOrder means this cannot return another
 * seller's rows even though it carries no explicit where clause — but the
 * caller is scoped anyway, so this is belt and braces.
 */
final class RecentSellerOrders
{
    /** @return array<int, SellerOrderSummary> */
    public function __invoke(int $limit = 10): array
    {
        return SellerOrder::query()
            ->with('marketplaceOrder')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (SellerOrder $order): SellerOrderSummary => new SellerOrderSummary(
                reference: $order->reference,
                customerName: $order->marketplaceOrder?->ship_name ?? '—',
                orderTotal: $order->orderTotal()->format(),
                sellerEarning: Money::of($order->seller_earning_total_minor, $order->currency)->format(),
                status: $order->status->value,
                placedAt: $order->created_at?->toDateString() ?? '',
            ))
            ->all();
    }
}
