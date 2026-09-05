<?php

declare(strict_types=1);

namespace App\Modules\Orders\Queries;

use App\Modules\Orders\Enums\SellerOrderStatus;
use App\Modules\Orders\Enums\ShipmentStatus;
use App\Modules\Orders\Models\MarketplaceOrder;
use App\Modules\Orders\Models\SellerOrder;
use App\Modules\Orders\Models\Shipment;
use App\Modules\Orders\Models\ShipmentItem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What a whole marketplace order is doing, derived from its sellers.
 *
 * §39 and §40. A customer who bought from three sellers has three parcels
 * arriving at three times, and the parent has no state of its own that can
 * describe that honestly — so this derives one, in one place, rather than
 * letting each page invent its own answer.
 *
 * The rule is the conservative one: the parent is only as far along as its
 * least advanced seller. Seller A delivered and Seller C still processing
 * is "partially delivered", never "delivered", because a customer told
 * their order arrived when a third of it has not is a customer who stops
 * believing the marketplace.
 *
 * Cancelled and fully refunded sellers are excluded from the reckoning
 * rather than dragging the parent backwards: an order where one seller
 * cancelled and the rest arrived is delivered, not stuck.
 */
final class SummariseOrderFulfilment
{
    /**
     * @return array{
     *     state: string,
     *     label: string,
     *     detail: string,
     *     sellerCount: int,
     *     deliveredCount: int,
     * }
     */
    public function forOrder(MarketplaceOrder $order): array
    {
        /** @var Collection<int, SellerOrder> $sellerOrders */
        $sellerOrders = SellerOrder::query()
            ->withoutGlobalScopes()
            ->where('marketplace_order_id', $order->id)
            ->orderBy('position')
            ->get();

        return $this->summarise($sellerOrders);
    }

    /**
     * Per-seller tracking groups, for the customer's own order page.
     *
     * Each seller's part stands on its own — its state, its parcels, its
     * tracking — because flattening them into one list would tell the
     * customer their order is "shipped" when one of three boxes has left.
     *
     * @param  Collection<int, SellerOrder>  $sellerOrders
     * @return array<int, array<string, mixed>>
     */
    public function groups(Collection $sellerOrders): array
    {
        if ($sellerOrders->isEmpty()) {
            return [];
        }

        $shipments = Shipment::query()
            ->whereIn('seller_order_id', $sellerOrders->pluck('id'))
            ->whereNot('status', ShipmentStatus::Cancelled->value)
            ->with('items.orderItem')
            ->orderBy('sequence')
            ->get()
            ->groupBy('seller_order_id');

        $stores = DB::table('stores')
            ->whereIn('id', $sellerOrders->pluck('store_id'))
            ->pluck('name', 'id');

        return $sellerOrders
            ->map(static fn (SellerOrder $sellerOrder): array => [
                'reference' => $sellerOrder->reference,
                'storeName' => (string) ($stores[$sellerOrder->store_id] ?? 'Seller'),
                'status' => $sellerOrder->status->value,
                'confirmedAt' => $sellerOrder->confirmed_at?->toIso8601String(),
                'shippedAt' => $sellerOrder->shipped_at?->toIso8601String(),
                'deliveredAt' => $sellerOrder->delivered_at?->toIso8601String(),
                /*
                 * Tracking, and nothing else about the parcel. Not the
                 * seller's notes, not who packed it, not the internal
                 * history — those are the seller's working record.
                 */
                'shipments' => ($shipments[$sellerOrder->id] ?? collect())
                    ->map(static fn (Shipment $shipment): array => [
                        'reference' => $shipment->reference,
                        'status' => $shipment->status->value,
                        'carrierName' => $shipment->carrier_name,
                        'trackingNumber' => $shipment->tracking_number,
                        'trackingUrl' => $shipment->tracking_url,
                        'shippedAt' => $shipment->shipped_at?->toIso8601String(),
                        'deliveredAt' => $shipment->delivered_at?->toIso8601String(),
                        'items' => $shipment->items
                            ->map(static fn (ShipmentItem $line): array => [
                                'title' => $line->orderItem->product_title,
                                'variantName' => $line->orderItem->variant_name,
                                'quantity' => $line->quantity,
                            ])
                            ->values()
                            ->all(),
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, SellerOrder>  $sellerOrders
     * @return array{state: string, label: string, detail: string, sellerCount: int, deliveredCount: int}
     */
    private function summarise(Collection $sellerOrders): array
    {
        $active = $sellerOrders->reject(
            static fn (SellerOrder $sellerOrder): bool => in_array($sellerOrder->status, [
                SellerOrderStatus::Cancelled,
                SellerOrderStatus::Refunded,
            ], true),
        );

        $total = $active->count();

        if ($total === 0) {
            return [
                'state' => 'closed',
                'label' => 'Closed',
                'detail' => 'Nothing on this order is still due to arrive.',
                'sellerCount' => $sellerOrders->count(),
                'deliveredCount' => 0,
            ];
        }

        $count = static fn (callable $matches): int => $active
            ->filter(static fn (SellerOrder $sellerOrder): bool => $matches($sellerOrder->status))
            ->count();

        $completed = $count(static fn (SellerOrderStatus $s): bool => $s === SellerOrderStatus::Completed);
        $delivered = $count(static fn (SellerOrderStatus $s): bool => $s->isFullyDelivered());
        $shipped = $count(static fn (SellerOrderStatus $s): bool => $s->hasShipped());
        $unpaid = $count(static fn (SellerOrderStatus $s): bool => $s === SellerOrderStatus::PendingPayment);

        $sellers = $total === 1 ? 'the seller' : "all {$total} sellers";

        return match (true) {
            $unpaid === $total => [
                'state' => 'awaiting_payment',
                'label' => 'Awaiting payment',
                'detail' => 'Nothing has been charged yet.',
                'sellerCount' => $total,
                'deliveredCount' => 0,
            ],
            $completed === $total => [
                'state' => 'completed',
                'label' => 'Completed',
                'detail' => 'Everything arrived and this order is closed.',
                'sellerCount' => $total,
                'deliveredCount' => $delivered,
            ],
            $delivered === $total => [
                'state' => 'delivered',
                'label' => 'Delivered',
                'detail' => "Everything from {$sellers} has arrived.",
                'sellerCount' => $total,
                'deliveredCount' => $delivered,
            ],
            $delivered > 0 => [
                'state' => 'partially_delivered',
                'label' => 'Partly delivered',
                'detail' => "{$delivered} of {$total} sellers have delivered; the rest are on their way.",
                'sellerCount' => $total,
                'deliveredCount' => $delivered,
            ],
            $shipped === $total => [
                'state' => 'shipped',
                'label' => 'Shipped',
                'detail' => "Everything from {$sellers} is on its way.",
                'sellerCount' => $total,
                'deliveredCount' => 0,
            ],
            $shipped > 0 => [
                'state' => 'partially_shipped',
                'label' => 'Partly shipped',
                'detail' => "{$shipped} of {$total} sellers have dispatched; the rest are preparing.",
                'sellerCount' => $total,
                'deliveredCount' => 0,
            ],
            default => [
                'state' => 'in_progress',
                'label' => 'Being prepared',
                'detail' => $total === 1
                    ? 'The seller is preparing your order.'
                    : 'Your sellers are preparing their parts of this order.',
                'sellerCount' => $total,
                'deliveredCount' => 0,
            ],
        };
    }
}
