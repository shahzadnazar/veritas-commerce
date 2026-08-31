<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Http\Controllers;

use App\Modules\Ledger\Queries\GetSellerBalance;
use App\Modules\Orders\Data\SellerOrderSummary;
use App\Modules\Orders\Queries\RecentSellerOrders;
use App\Modules\Sellers\Concerns\CurrentSeller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controllers orchestrate: authorise, call a query, hand the result to a
 * view. No business rules, and no reaching into another module's models —
 * the order queue arrives as DTOs from the Orders module.
 */
final class SellerDashboardController
{
    public function __construct(
        private readonly GetSellerBalance $getBalance,
        private readonly RecentSellerOrders $recentOrders,
    ) {}

    public function __invoke(): Response
    {
        $membership = CurrentSeller::membership();
        $seller = $membership?->sellerAccount;

        abort_if($seller === null, 404);

        $balance = ($this->getBalance)($seller->id);

        return Inertia::render('Dashboard', [
            'store' => [
                'name' => $seller->store?->name ?? $seller->legal_name,
                'status' => $seller->status->value,
            ],
            'balance' => [
                'clearing' => $balance->clearing->format(),
                'available' => $balance->available->format(),
                'held' => $balance->held->format(),
            ],
            'recentOrders' => array_map(
                static fn (SellerOrderSummary $order): array => [
                    'reference' => $order->reference,
                    'customer' => $order->customerName,
                    'total' => $order->orderTotal,
                    'earning' => $order->sellerEarning,
                    'status' => $order->status,
                ],
                ($this->recentOrders)(),
            ),
        ]);
    }
}
