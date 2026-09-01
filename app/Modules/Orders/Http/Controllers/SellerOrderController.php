<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers;

use App\Modules\Orders\Enums\SellerOrderStatus;
use App\Modules\Orders\Models\MarketplaceOrder;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\SellerOrder;
use App\Modules\Orders\Queries\BuildOrderDetail;
use App\Modules\Sellers\Concerns\CurrentSeller;
use App\Modules\Sellers\Enums\SellerPermission;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A seller's own half of the marketplace's orders.
 *
 * SellerOrder carries a global tenant scope, so a query with no where
 * clause already cannot return another seller's rows — but the point of
 * this controller is what it does NOT read. It never loads the parent
 * order's other children, so there is no path by which one seller's screen
 * could render another seller's items, totals or earnings even by mistake.
 *
 * The parent reference is shown because a customer quotes it to support.
 * Nothing else about the parent crosses over.
 *
 * Commission and earnings are behind the seller's own finance permission:
 * a warehouse account that packs boxes has no reason to see the platform's
 * cut, and the role matrix already draws that line elsewhere.
 */
final class SellerOrderController
{
    public function __construct(private readonly BuildOrderDetail $detail) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'reference' => ['nullable', 'string', 'max:64'],
            'parent' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', 'string', 'max:32'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $query = SellerOrder::query()->orderByDesc('id');

        if (($filters['reference'] ?? null) !== null) {
            $query->where('reference', 'ilike', '%'.$filters['reference'].'%');
        }

        if (($filters['parent'] ?? null) !== null) {
            // Resolved through this seller's own rows: filtering by a
            // parent reference must not become a way to ask whether one
            // exists.
            $query->whereIn(
                'marketplace_order_id',
                MarketplaceOrder::query()->where('reference', 'ilike', '%'.$filters['parent'].'%')->select('id'),
            );
        }

        if (($filters['status'] ?? null) !== null && SellerOrderStatus::tryFrom($filters['status']) !== null) {
            $query->where('status', $filters['status']);
        }

        if (($filters['from'] ?? null) !== null) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }

        if (($filters['to'] ?? null) !== null) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }

        /** @var LengthAwarePaginator<int, SellerOrder> $orders */
        $orders = $query->paginate(25)->withQueryString();

        // Two lookups for the page rather than one per row: the parent
        // references, and the line counts.
        $parents = MarketplaceOrder::query()
            ->whereIn('id', array_map(static fn (SellerOrder $o): int => $o->marketplace_order_id, $orders->items()))
            ->pluck('reference', 'id');

        $counts = OrderItem::query()
            ->whereIn('seller_order_id', array_map(static fn (SellerOrder $o): int => $o->id, $orders->items()))
            ->selectRaw('seller_order_id, count(*) as lines, sum(quantity) as units')
            ->groupBy('seller_order_id')
            ->get()
            ->keyBy('seller_order_id');

        return Inertia::render('Orders/Index', [
            'orders' => [
                'data' => array_map(
                    static fn (SellerOrder $order): array => [
                        'reference' => $order->reference,
                        'parentReference' => $parents[$order->marketplace_order_id] ?? null,
                        'placedAt' => $order->created_at?->toIso8601String(),
                        'status' => $order->status->value,
                        'lineCount' => (int) ($counts[$order->id]->lines ?? 0),
                        'unitCount' => (int) ($counts[$order->id]->units ?? 0),
                        'orderTotal' => Money::of($order->order_total_minor, $order->currency)->format(),
                        'orderTotalMinor' => $order->order_total_minor,
                    ],
                    $orders->items(),
                ),
                'currentPage' => $orders->currentPage(),
                'lastPage' => $orders->lastPage(),
                'total' => $orders->total(),
            ],
            'filters' => $filters,
            'statuses' => array_map(
                static fn (SellerOrderStatus $status): array => ['value' => $status->value, 'label' => $status->label()],
                SellerOrderStatus::cases(),
            ),
        ]);
    }

    public function show(Request $request, string $reference): Response
    {
        /** @var SellerOrder|null $sellerOrder */
        $sellerOrder = SellerOrder::query()->where('reference', $reference)->first();

        if ($sellerOrder === null) {
            // The tenant scope already removed another seller's rows, so
            // this is the 404 that keeps their existence private too.
            throw new NotFoundHttpException;
        }

        $withFinance = CurrentSeller::can(SellerPermission::FinanceView);

        $items = OrderItem::query()
            ->where('seller_order_id', $sellerOrder->id)
            ->orderBy('id')
            ->get()
            ->all();

        /** @var MarketplaceOrder $parent */
        $parent = MarketplaceOrder::query()->findOrFail($sellerOrder->marketplace_order_id);

        return Inertia::render('Orders/Show', [
            'sellerOrder' => $this->detail->sellerOrder($sellerOrder, $items, $withFinance),
            // The parent's reference and the destination, because a parcel
            // has to be addressed and a customer quotes the parent number.
            // Not its totals, not its other sellers, not its other items.
            'parent' => [
                'reference' => $parent->reference,
                'status' => $parent->status->value,
                'placedAt' => $parent->placed_at?->toIso8601String(),
                'shippingAddress' => [
                    'name' => $parent->ship_name,
                    'line1' => $parent->ship_line1,
                    'line2' => $parent->ship_line2,
                    'city' => $parent->ship_city,
                    'state' => $parent->ship_state,
                    'postcode' => $parent->ship_postcode,
                    'country' => $parent->ship_country,
                    'phone' => $parent->ship_phone,
                ],
            ],
            /*
             * §14's policy, stated as data the page cannot argue with.
             *
             * An unpaid seller order is visible for traceability and is
             * not actionable. Fulfilment belongs to a later milestone in
             * any case; that this flag is false before payment is the
             * part that has to be true now.
             */
            'fulfilment' => [
                'actionable' => $sellerOrder->status !== SellerOrderStatus::PendingPayment,
                'reason' => $sellerOrder->status === SellerOrderStatus::PendingPayment
                    ? 'This order cannot be packed or shipped until payment is confirmed.'
                    : null,
            ],
            'canSeeFinance' => $withFinance,
        ]);
    }
}
