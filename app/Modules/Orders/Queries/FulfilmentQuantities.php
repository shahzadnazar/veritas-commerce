<?php

declare(strict_types=1);

namespace App\Modules\Orders\Queries;

use App\Modules\Orders\Data\ItemFulfilment;
use App\Modules\Orders\Enums\ShipmentStatus;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\SellerOrder;
use App\Modules\Payments\Queries\RefundedQuantities;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The one calculation of what is left to ship.
 *
 * Every surface that needs it — the seller's packing screen, the customer's
 * tracking page, the admin's fulfilment view, and the domain action that
 * decides whether a shipment may be created — reads it from here. §64
 * exists because the alternative is three screens quietly disagreeing
 * about one order, and the disagreement showing up as a seller shipping a
 * unit the customer was already refunded for.
 *
 * Four queries regardless of how many items a seller order has: the items,
 * their refunded units, their shipped units, and nothing per row.
 */
final class FulfilmentQuantities
{
    public function __construct(private readonly RefundedQuantities $refunded) {}

    /**
     * @return array<int, ItemFulfilment> keyed by order item id, in item order
     */
    public function forSellerOrder(SellerOrder $sellerOrder): array
    {
        /** @var Collection<int, OrderItem> $items */
        $items = OrderItem::query()
            ->where('seller_order_id', $sellerOrder->id)
            ->orderBy('id')
            ->get();

        return $this->forItems($items);
    }

    /**
     * @param  Collection<int, OrderItem>  $items
     * @return array<int, ItemFulfilment>
     */
    public function forItems(Collection $items): array
    {
        if ($items->isEmpty()) {
            return [];
        }

        /** @var array<int, int> $ids */
        $ids = $items->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

        $refunded = ($this->refunded)($ids);
        $shipped = $this->shippedUnits($ids);

        $out = [];

        foreach ($items as $item) {
            $out[(int) $item->id] = new ItemFulfilment(
                orderItemId: (int) $item->id,
                publicId: $item->public_id,
                title: $item->product_title,
                variantName: $item->variant_name,
                sku: $item->seller_sku,
                ordered: $item->quantity,
                refunded: $refunded[(int) $item->id] ?? 0,
                allocated: $item->allocated_quantity,
                shipped: $shipped[(int) $item->id] ?? 0,
                delivered: $item->delivered_quantity,
            );
        }

        return $out;
    }

    /**
     * Units in a parcel that has actually left.
     *
     * Derived rather than stored, because it is a fact about the parcels'
     * states and would otherwise be a third counter to keep in step. The
     * states that count as "gone" live on the enum, so a new one cannot be
     * added without this following it.
     *
     * @param  array<int, int>  $orderItemIds
     * @return array<int, int>
     */
    private function shippedUnits(array $orderItemIds): array
    {
        /** @var array<int, int> $rows */
        $rows = DB::table('shipment_items')
            ->join('shipments', 'shipments.id', '=', 'shipment_items.shipment_id')
            ->whereIn('shipment_items.order_item_id', $orderItemIds)
            ->whereIn('shipments.status', $this->goneStatuses())
            ->groupBy('shipment_items.order_item_id')
            ->selectRaw('shipment_items.order_item_id as item_id, sum(shipment_items.quantity) as units')
            ->pluck('units', 'item_id')
            ->map(static fn (mixed $units): int => (int) $units)
            ->all();

        return $rows;
    }

    /** @return array<int, string> */
    private function goneStatuses(): array
    {
        return array_values(array_map(
            static fn (ShipmentStatus $status): string => $status->value,
            array_filter(
                ShipmentStatus::cases(),
                static fn (ShipmentStatus $status): bool => $status->hasLeft(),
            ),
        ));
    }
}
