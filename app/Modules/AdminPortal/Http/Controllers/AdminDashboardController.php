<?php

declare(strict_types=1);

namespace App\Modules\AdminPortal\Http\Controllers;

use App\Modules\Commission\Actions\ResolveCommissionRule;
use App\Modules\Offers\Enums\OfferStatus;
use App\Modules\Offers\Models\Offer;
use App\Modules\Orders\Models\SellerOrder;
use App\Modules\Payouts\Models\PayoutRequest;
use App\Modules\Sellers\Concerns\CurrentSeller;
use App\Modules\Sellers\Models\SellerAccount;
use App\Modules\Sellers\Models\SellerApplication;
use App\Support\Money;
use Inertia\Inertia;
use Inertia\Response;

final class AdminDashboardController
{
    public function __construct(private readonly ResolveCommissionRule $resolveRule) {}

    public function __invoke(): Response
    {
        // Admin reads across every seller, through the one explicit,
        // named escape from the tenant scope rather than by omission.
        return CurrentSeller::withoutScope(function (): Response {
            $rate = ($this->resolveRule)();

            $recentOrders = SellerOrder::query()
                ->with(['sellerAccount', 'marketplaceOrder'])
                ->latest('id')
                ->limit(10)
                ->get()
                ->map(fn (SellerOrder $order): array => [
                    'reference' => $order->reference,
                    'seller' => $order->sellerAccount->legal_name ?? 'Unknown seller',
                    'total' => $order->orderTotal()->format(),
                    // Null, not zero: a failed payment produced no split.
                    'commission' => $order->commission_total_minor > 0
                        ? Money::of($order->commission_total_minor, $order->currency)->format()
                        : null,
                    'status' => $order->status->value,
                ])
                ->all();

            return Inertia::render('Dashboard', [
                'queues' => [
                    [
                        'label' => 'Seller applications',
                        'count' => SellerApplication::query()->whereIn('status', ['submitted', 'under_review'])->count(),
                        'note' => 'Awaiting a decision',
                    ],
                    [
                        'label' => 'Offers to review',
                        'count' => Offer::query()->where('status', OfferStatus::PendingReview->value)->count(),
                        'note' => 'Awaiting moderation',
                    ],
                    [
                        'label' => 'Payout requests',
                        'count' => PayoutRequest::query()->where('status', 'requested')->count(),
                        'note' => 'Awaiting review',
                    ],
                    [
                        'label' => 'Active sellers',
                        'count' => SellerAccount::query()->where('status', 'approved')->count(),
                        'note' => 'Trading now',
                    ],
                ],
                'recentOrders' => $recentOrders,
                'commissionRate' => $rate->ratePercent(),
            ]);
        });
    }
}
