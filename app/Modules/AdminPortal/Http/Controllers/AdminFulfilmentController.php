<?php

declare(strict_types=1);

namespace App\Modules\AdminPortal\Http\Controllers;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Identity\Enums\AdminPermission;
use App\Modules\Ledger\Enums\LedgerEntryStatus;
use App\Modules\Ledger\Models\SellerLedgerEntry;
use App\Modules\Orders\Actions\MarkShipmentDelivered;
use App\Modules\Orders\Actions\UpdateShipmentTracking;
use App\Modules\Orders\Data\ShipmentTracking;
use App\Modules\Orders\Enums\SellerOrderStatus;
use App\Modules\Orders\Exceptions\FulfilmentRefused;
use App\Modules\Orders\Models\MarketplaceOrder;
use App\Modules\Orders\Models\SellerOrder;
use App\Modules\Orders\Models\Shipment;
use App\Modules\Orders\Queries\BuildFulfilmentView;
use App\Modules\Orders\Queries\SummariseOrderFulfilment;
use App\Modules\Orders\Support\Carriers;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The platform's view of fulfilment across every seller.
 *
 * The one place that legitimately reads across sellers, because answering
 * "where is VC-24081" means seeing all of it. Every seller-facing surface
 * is deliberately partial; this is not.
 *
 * The write side is deliberately narrow. There is no "set status"
 * dropdown: an admin can correct tracking and record a delivery, and each
 * takes its own permission and a written reason. A free-form status
 * setter would let an operator put an order into a state the domain has no
 * route to, and the next person to read it would have no way of knowing
 * how it got there.
 */
final class AdminFulfilmentController
{
    public function __construct(
        private readonly BuildFulfilmentView $fulfilment,
        private readonly SummariseOrderFulfilment $summary,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'reference' => ['nullable', 'string', 'max:64'],
            'store' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', 'string', 'max:32'],
            'carrier' => ['nullable', 'string', 'max:64'],
            'tracking' => ['nullable', 'string', 'max:100'],
            'clearing' => ['nullable', 'string', 'max:16'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $query = SellerOrder::query()
            ->withoutGlobalScopes()
            // Fulfilment starts at payment; an unpaid order is not
            // operational work and would only be noise on this screen.
            ->whereNot('status', SellerOrderStatus::PendingPayment->value)
            ->orderByDesc('id');

        if (($filters['status'] ?? null) !== null && SellerOrderStatus::tryFrom($filters['status']) !== null) {
            $query->where('status', $filters['status']);
        }

        if (($filters['reference'] ?? null) !== null) {
            $reference = $filters['reference'];

            $query->where(function ($builder) use ($reference): void {
                $builder
                    ->where('reference', 'ilike', '%'.$reference.'%')
                    ->orWhereIn(
                        'marketplace_order_id',
                        MarketplaceOrder::query()
                            ->where('reference', 'ilike', '%'.$reference.'%')
                            ->select('id'),
                    );
            });
        }

        if (($filters['store'] ?? null) !== null) {
            $query->whereIn('store_id', DB::table('stores')
                ->where('name', 'ilike', '%'.$filters['store'].'%')
                ->select('id'));
        }

        if (($filters['carrier'] ?? null) !== null || ($filters['tracking'] ?? null) !== null) {
            $query->whereIn('id', Shipment::query()
                ->when(
                    ($filters['carrier'] ?? null) !== null,
                    fn ($builder) => $builder->where('carrier_name', 'ilike', '%'.$filters['carrier'].'%'),
                )
                ->when(
                    ($filters['tracking'] ?? null) !== null,
                    fn ($builder) => $builder->where('tracking_number', 'ilike', '%'.$filters['tracking'].'%'),
                )
                ->select('seller_order_id'));
        }

        if (($filters['clearing'] ?? null) === 'due') {
            $query->whereNotNull('earnings_clear_at')
                ->where('earnings_clear_at', '<=', now())
                ->whereNull('completed_at');
        }

        if (($filters['clearing'] ?? null) === 'clearing') {
            $query->whereNotNull('earnings_clear_at')
                ->where('earnings_clear_at', '>', now());
        }

        if (($filters['from'] ?? null) !== null) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }

        if (($filters['to'] ?? null) !== null) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }

        /** @var LengthAwarePaginator<int, SellerOrder> $orders */
        $orders = $query->paginate(25)->withQueryString();

        // Two lookups for the page, not one per row.
        $parents = MarketplaceOrder::query()
            ->whereIn('id', array_map(
                static fn (SellerOrder $sellerOrder): int => (int) $sellerOrder->marketplace_order_id,
                $orders->items(),
            ))
            ->pluck('reference', 'id');

        $stores = DB::table('stores')
            ->whereIn('id', array_map(
                static fn (SellerOrder $sellerOrder): int => (int) $sellerOrder->store_id,
                $orders->items(),
            ))
            ->pluck('name', 'id');

        return Inertia::render('Fulfilment/Index', [
            'orders' => [
                'data' => array_map(
                    static fn (SellerOrder $sellerOrder): array => [
                        'reference' => $sellerOrder->reference,
                        'parentReference' => (string) ($parents[$sellerOrder->marketplace_order_id] ?? '—'),
                        'storeName' => (string) ($stores[$sellerOrder->store_id] ?? 'Seller'),
                        'status' => $sellerOrder->status->value,
                        'placedAt' => $sellerOrder->created_at?->toIso8601String(),
                        'shippedAt' => $sellerOrder->shipped_at?->toIso8601String(),
                        'deliveredAt' => $sellerOrder->delivered_at?->toIso8601String(),
                        'earningsClearAt' => $sellerOrder->earnings_clear_at?->toIso8601String(),
                        'completedAt' => $sellerOrder->completed_at?->toIso8601String(),
                        'orderTotal' => Money::of($sellerOrder->order_total_minor, $sellerOrder->currency)->format(),
                    ],
                    $orders->items(),
                ),
                'currentPage' => $orders->currentPage(),
                'lastPage' => $orders->lastPage(),
                'total' => $orders->total(),
            ],
            'filters' => $filters,
            'statuses' => array_map(
                static fn (SellerOrderStatus $status): array => [
                    'value' => $status->value,
                    'label' => $status->label(),
                ],
                array_values(array_filter(
                    SellerOrderStatus::cases(),
                    static fn (SellerOrderStatus $status): bool => $status !== SellerOrderStatus::PendingPayment,
                )),
            ),
        ]);
    }

    public function show(Request $request, string $reference): Response
    {
        $sellerOrder = $this->sellerOrderOrFail($reference);

        /** @var MarketplaceOrder $parent */
        $parent = MarketplaceOrder::query()->findOrFail($sellerOrder->marketplace_order_id);

        $admin = $request->user('admin');
        $mayOverride = $admin !== null && $admin->role->can(AdminPermission::OverrideFulfilment);
        $mayCorrect = $admin !== null && $admin->role->can(AdminPermission::CorrectTracking);
        $maySeeClearing = $admin !== null && $admin->role->can(AdminPermission::ViewEarningsClearing);

        return Inertia::render('Fulfilment/Show', [
            'sellerOrder' => [
                'reference' => $sellerOrder->reference,
                'status' => $sellerOrder->status->value,
                'storeName' => $this->storeName($sellerOrder),
                'confirmedAt' => $sellerOrder->confirmed_at?->toIso8601String(),
                'packedAt' => $sellerOrder->packed_at?->toIso8601String(),
                'shippedAt' => $sellerOrder->shipped_at?->toIso8601String(),
                'deliveredAt' => $sellerOrder->delivered_at?->toIso8601String(),
                'completedAt' => $sellerOrder->completed_at?->toIso8601String(),
                'earningsClearAt' => $sellerOrder->earnings_clear_at?->toIso8601String(),
                'orderTotal' => Money::of($sellerOrder->order_total_minor, $sellerOrder->currency)->format(),
            ],
            'parent' => [
                'reference' => $parent->reference,
                'status' => $parent->status->value,
                'summary' => $this->summary->forOrder($parent),
            ],
            'fulfilment' => $this->fulfilment->forSellerOrder($sellerOrder, withHistory: true),
            'earnings' => $maySeeClearing ? $this->earnings($sellerOrder) : [],
            'carriers' => Carriers::all(),
            'can' => [
                'override' => $mayOverride,
                'correctTracking' => $mayCorrect,
                'viewClearing' => $maySeeClearing,
            ],
        ]);
    }

    /**
     * Record that a parcel arrived, on a seller's behalf.
     *
     * The platform contradicting a seller's own record of their own
     * shipment, so it takes the override permission and a reason that goes
     * into the shipment's history. It still runs the same domain action a
     * seller would — there is no separate admin path that could put a
     * parcel somewhere the domain has no route to.
     */
    public function deliver(Request $request, string $reference, string $shipment): RedirectResponse
    {
        $sellerOrder = $this->sellerOrderOrFail($reference);
        $parcel = $this->parcelOrFail($sellerOrder, $shipment);
        $admin = $request->user('admin');

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);

        try {
            $arrived = app(MarkShipmentDelivered::class)(
                $parcel,
                actorType: 'admin',
                actorId: $admin === null ? null : (int) $admin->getAuthIdentifier(),
                reason: $validated['reason'],
            );
        } catch (FulfilmentRefused $refused) {
            return back()->withErrors(['fulfilment' => $refused->getMessage()]);
        }

        if ($arrived) {
            $this->record('fulfilment.override.delivered', $sellerOrder, $request, [
                'shipment_reference' => $parcel->reference,
            ], $validated['reason']);
        }

        return back()->with('status', "Recorded {$parcel->reference} as delivered.");
    }

    /**
     * Correct the tracking on a parcel, including a delivered one.
     *
     * §15: after delivery the tracking is the evidence the parcel arrived,
     * so this is the only route that may change it — behind its own
     * permission, with a reason kept beside the value it replaced.
     */
    public function correctTracking(Request $request, string $reference, string $shipment): RedirectResponse
    {
        $sellerOrder = $this->sellerOrderOrFail($reference);
        $parcel = $this->parcelOrFail($sellerOrder, $shipment);
        $admin = $request->user('admin');

        $validated = $request->validate([
            'carrier' => ['required', 'string', 'max:120'],
            'tracking_number' => ['required', 'string', 'max:100'],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);

        try {
            $changed = app(UpdateShipmentTracking::class)(
                $parcel,
                ShipmentTracking::of($validated['carrier'], $validated['tracking_number']),
                actorType: 'admin',
                actorId: $admin === null ? null : (int) $admin->getAuthIdentifier(),
                reason: $validated['reason'],
                mayCorrectHistory: true,
            );
        } catch (FulfilmentRefused $refused) {
            return back()->withErrors(['fulfilment' => $refused->getMessage()]);
        }

        if ($changed) {
            $this->record('fulfilment.override.tracking_corrected', $sellerOrder, $request, [
                'shipment_reference' => $parcel->reference,
                'carrier' => $parcel->carrier_name,
            ], $validated['reason']);
        }

        return back()->with('status', 'Tracking corrected.');
    }

    /**
     * The seller's money for this order, entry by entry.
     *
     * Read from the ledger, which is the financial truth — not summed from
     * the order's own totals, which are a summary of intent and drift the
     * moment a refund is issued.
     *
     * @return array<int, array<string, mixed>>
     */
    private function earnings(SellerOrder $sellerOrder): array
    {
        return SellerLedgerEntry::query()
            ->withoutGlobalScopes()
            ->where('seller_order_id', $sellerOrder->id)
            ->orderBy('id')
            ->get()
            ->map(static fn (SellerLedgerEntry $entry): array => [
                'publicId' => $entry->public_id,
                'type' => $entry->type->value,
                'status' => $entry->status->value,
                'amountMinor' => $entry->amount_minor,
                'amount' => Money::of(abs($entry->amount_minor), $entry->currency)->format(),
                'availableAt' => $entry->available_at?->toIso8601String(),
                'note' => $entry->note,
                'reversesEntryId' => $entry->reverses_entry_id,
                'createdAt' => $entry->created_at->toIso8601String(),
                'isAvailable' => $entry->status === LedgerEntryStatus::Available,
            ])
            ->all();
    }

    private function sellerOrderOrFail(string $reference): SellerOrder
    {
        /** @var SellerOrder $sellerOrder */
        $sellerOrder = SellerOrder::query()
            ->withoutGlobalScopes()
            ->where('reference', $reference)
            ->firstOrFail();

        return $sellerOrder;
    }

    private function parcelOrFail(SellerOrder $sellerOrder, string $publicId): Shipment
    {
        /** @var Shipment $shipment */
        $shipment = Shipment::query()
            ->where('seller_order_id', $sellerOrder->id)
            ->where('public_id', $publicId)
            ->firstOrFail();

        return $shipment;
    }

    private function storeName(SellerOrder $sellerOrder): string
    {
        $name = DB::table('stores')->where('id', $sellerOrder->store_id)->value('name');

        return is_string($name) ? $name : 'Seller';
    }

    /** @param array<string, mixed> $changes */
    private function record(
        string $action,
        SellerOrder $sellerOrder,
        Request $request,
        array $changes,
        string $reason,
    ): void {
        $admin = $request->user('admin');

        ($this->audit)(
            action: $action,
            actorType: 'admin',
            actorId: $admin === null ? null : (int) $admin->getAuthIdentifier(),
            subjectType: SellerOrder::class,
            subjectId: $sellerOrder->id,
            changes: array_merge(['seller_order' => $sellerOrder->reference], $changes),
            reason: $reason,
        );
    }
}
