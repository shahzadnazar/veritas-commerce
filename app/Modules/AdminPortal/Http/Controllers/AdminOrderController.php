<?php

declare(strict_types=1);

namespace App\Modules\AdminPortal\Http\Controllers;

use App\Modules\Checkout\Models\CheckoutAttempt;
use App\Modules\Identity\Enums\AdminPermission;
use App\Modules\Inventory\Models\InventoryReservation;
use App\Modules\Orders\Enums\MarketplaceOrderStatus;
use App\Modules\Orders\Models\MarketplaceOrder;
use App\Modules\Orders\Models\OrderStatusHistory;
use App\Modules\Orders\Models\SellerOrder;
use App\Modules\Orders\Queries\BuildOrderDetail;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The platform's own view of an order: the whole hierarchy at once.
 *
 * The one place that legitimately reads across every seller in a
 * marketplace order, because answering "what happened to VC-24081" means
 * seeing all of it. Every other order surface is deliberately partial.
 *
 * Finance is a second permission, not a consequence of being staff. §18:
 * support answers "where is my parcel" and has no business knowing what
 * the platform took from each seller on that order. `orders.view` opens
 * the page; `orders.view_sensitive` fills in the commission columns.
 */
final class AdminOrderController
{
    public function __construct(private readonly BuildOrderDetail $detail) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'reference' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', 'string', 'max:32'],
            'seller' => ['nullable', 'string', 'max:64'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $query = MarketplaceOrder::query()
            // Counted in SQL, not by loading children: a list page that
            // hydrated every order's aggregate would read the whole
            // marketplace to render twenty-five rows.
            ->withCount('sellerOrders')
            ->orderByDesc('id');

        if (($filters['reference'] ?? null) !== null) {
            $query->where('reference', 'ilike', '%'.$filters['reference'].'%');
        }

        if (($filters['status'] ?? null) !== null && MarketplaceOrderStatus::tryFrom($filters['status']) !== null) {
            $query->where('status', $filters['status']);
        }

        if (($filters['seller'] ?? null) !== null) {
            $query->whereIn('id', SellerOrder::query()
                ->withoutGlobalScopes()
                ->whereIn('store_id', fn ($sub) => $sub
                    ->from('stores')
                    ->where('name', 'ilike', '%'.$filters['seller'].'%')
                    ->select('id'))
                ->select('marketplace_order_id'));
        }

        if (($filters['from'] ?? null) !== null) {
            $query->whereDate('placed_at', '>=', $filters['from']);
        }

        if (($filters['to'] ?? null) !== null) {
            $query->whereDate('placed_at', '<=', $filters['to']);
        }

        /** @var LengthAwarePaginator<int, MarketplaceOrder> $orders */
        $orders = $query->paginate(25)->withQueryString();

        return Inertia::render('Orders/Index', [
            'orders' => [
                'data' => array_map(
                    static fn (MarketplaceOrder $order): array => [
                        'reference' => $order->reference,
                        'placedAt' => $order->placed_at?->toIso8601String(),
                        'status' => $order->status->value,
                        'email' => $order->email,
                        'customerName' => $order->ship_name,
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
            'filters' => $filters,
            'statuses' => array_map(
                static fn (MarketplaceOrderStatus $status): array => [
                    'value' => $status->value,
                    'label' => $status->label(),
                ],
                MarketplaceOrderStatus::cases(),
            ),
        ]);
    }

    public function show(Request $request, string $reference): Response
    {
        /** @var MarketplaceOrder $order */
        $order = MarketplaceOrder::query()->where('reference', $reference)->firstOrFail();

        $admin = $request->user('admin');
        $withFinance = $admin !== null && $admin->role->can(AdminPermission::ViewOrdersSensitive);

        return Inertia::render('Orders/Show', [
            'order' => ($this->detail)($order, withFinance: $withFinance),
            'canSeeFinance' => $withFinance,
            'checkout' => $this->checkout($order),
            'reservations' => $this->reservations($order),
            'payments' => $this->payments($order),
            'history' => $this->history($order),
        ]);
    }

    /** @return array<string, mixed>|null */
    private function checkout(MarketplaceOrder $order): ?array
    {
        if ($order->checkout_attempt_id === null) {
            return null;
        }

        /** @var CheckoutAttempt|null $attempt */
        $attempt = CheckoutAttempt::query()->find($order->checkout_attempt_id);

        return $attempt === null ? null : [
            'publicId' => $attempt->public_id,
            'status' => $attempt->status->value,
            'expiresAt' => $attempt->expires_at?->toIso8601String(),
            'completedAt' => $attempt->completed_at?->toIso8601String(),
            'failureReason' => $attempt->failure_reason,
            'reservationReference' => $attempt->reservationReference(),
        ];
    }

    /**
     * The holds this order took, so an operator can answer "why is that
     * offer showing as unavailable".
     *
     * @return array<int, array<string, mixed>>
     */
    private function reservations(MarketplaceOrder $order): array
    {
        if ($order->reservation_reference === null) {
            return [];
        }

        return InventoryReservation::query()
            ->where('reference', $order->reservation_reference)
            ->orderBy('id')
            ->get()
            ->map(static fn (InventoryReservation $reservation): array => [
                'offerId' => $reservation->offer_id,
                'quantity' => $reservation->quantity,
                'status' => $reservation->status->value,
                'expiresAt' => $reservation->expires_at?->toIso8601String(),
                'resolvedAt' => $reservation->resolved_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Payment attempts as the platform recorded them.
     *
     * The provider's own reference and status only — no gateway payloads,
     * no card data, nothing that came back from a webhook body.
     *
     * @return array<int, array<string, mixed>>
     */
    private function payments(MarketplaceOrder $order): array
    {
        return PaymentAttempt::query()
            ->where('marketplace_order_id', $order->id)
            ->orderBy('id')
            ->get()
            ->map(static fn (PaymentAttempt $attempt): array => [
                'status' => $attempt->status->value,
                'provider' => $attempt->provider,
                'reference' => $attempt->provider_reference,
                'createdAt' => $attempt->created_at->toIso8601String(),
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function history(MarketplaceOrder $order): array
    {
        $sellerOrderIds = SellerOrder::query()
            ->withoutGlobalScopes()
            ->where('marketplace_order_id', $order->id)
            ->pluck('reference', 'id');

        return OrderStatusHistory::query()
            ->where('marketplace_order_id', $order->id)
            ->orWhereIn('seller_order_id', $sellerOrderIds->keys())
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(static fn (OrderStatusHistory $row): array => [
                'scope' => $row->seller_order_id === null
                    ? $order->reference
                    : ($sellerOrderIds[$row->seller_order_id] ?? '—'),
                'from' => $row->from_status,
                'to' => $row->to_status,
                'actorType' => $row->actor_type,
                'note' => $row->note,
                'at' => $row->created_at->toIso8601String(),
            ])
            ->all();
    }
}
