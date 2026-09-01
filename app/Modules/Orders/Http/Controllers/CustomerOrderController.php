<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers;

use App\Modules\Orders\Models\MarketplaceOrder;
use App\Modules\Orders\Models\SellerOrder;
use App\Modules\Orders\Queries\BuildOrderDetail;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A customer's own orders.
 *
 * The reference in the URL is a lookup key, never an authorization. Every
 * query is scoped by `user_id` first, so another customer's reference —
 * guessed, incremented or shoulder-surfed — resolves to nothing and
 * returns a 404. A 403 would confirm that the order exists, which is
 * itself something a stranger should not learn.
 *
 * Money and descriptions come from the order's own snapshot columns.
 */
final class CustomerOrderController
{
    public function __construct(private readonly BuildOrderDetail $detail) {}

    public function index(Request $request): Response
    {
        $userId = (int) $request->user('web')?->getAuthIdentifier();

        /** @var LengthAwarePaginator<int, MarketplaceOrder> $orders */
        $orders = MarketplaceOrder::query()
            ->where('user_id', $userId)
            ->withCount('sellerOrders')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Account/Orders/Index', [
            'orders' => [
                'data' => array_map(
                    fn (MarketplaceOrder $order): array => [
                        'reference' => $order->reference,
                        'placedAt' => $order->placed_at?->toIso8601String(),
                        'status' => $order->status->value,
                        'sellerOrderCount' => (int) ($order->seller_orders_count ?? 0),
                        'grandTotal' => Money::of($order->grand_total_minor, $order->currency)->format(),
                        'grandTotalMinor' => $order->grand_total_minor,
                    ],
                    $orders->items(),
                ),
                'currentPage' => $orders->currentPage(),
                'lastPage' => $orders->lastPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    public function show(Request $request, string $reference): Response
    {
        $order = $this->ownedOrFail($request, $reference);

        return Inertia::render('Account/Orders/Show', [
            // A customer sees what they paid, never what the platform
            // took from the seller: that is the seller's business and the
            // platform's, and none of the customer's.
            'order' => ($this->detail)($order, withFinance: false),
            'sellerOrderStatuses' => SellerOrder::query()
                ->withoutGlobalScopes()
                ->where('marketplace_order_id', $order->id)
                ->orderBy('position')
                ->pluck('status', 'reference')
                ->all(),
        ]);
    }

    private function ownedOrFail(Request $request, string $reference): MarketplaceOrder
    {
        $userId = (int) $request->user('web')?->getAuthIdentifier();

        /** @var MarketplaceOrder|null $order */
        $order = MarketplaceOrder::query()
            ->where('user_id', $userId)
            ->where('reference', $reference)
            ->first();

        if ($order === null) {
            // Indistinguishable from a reference that does not exist.
            throw new NotFoundHttpException;
        }

        return $order;
    }
}
