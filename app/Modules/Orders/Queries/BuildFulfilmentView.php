<?php

declare(strict_types=1);

namespace App\Modules\Orders\Queries;

use App\Modules\Orders\Enums\ShipmentStatus;
use App\Modules\Orders\Models\FulfilmentIssue;
use App\Modules\Orders\Models\SellerOrder;
use App\Modules\Orders\Models\Shipment;
use App\Modules\Orders\Models\ShipmentItem;
use App\Modules\Orders\Models\ShipmentStatusHistory;
use App\Modules\Orders\Support\Carriers;
use Illuminate\Database\Eloquent\Collection;

/**
 * The typed fulfilment view every surface reads.
 *
 * §64 and §65 in one place: the seller's packing screen, the customer's
 * tracking page and the admin's operational view all take their shipments,
 * their quantities and their remaining work from here. Not from an Eloquent
 * graph serialised straight to React — that is how a relationship added
 * for one screen quietly starts loading on three, and how the shape the
 * frontend types against drifts from the shape the backend sends.
 *
 * Bounded queries throughout: four for the parcels of a seller order
 * regardless of how many parcels or items it has, and never one per row.
 */
final class BuildFulfilmentView
{
    public function __construct(private readonly FulfilmentQuantities $quantities) {}

    /**
     * @return array{
     *     items: array<int, array<string, mixed>>,
     *     shipments: array<int, array<string, mixed>>,
     *     issues: array<int, array<string, mixed>>,
     *     carriers: array<int, array{code: string, name: string}>,
     *     remainingUnits: int,
     * }
     */
    public function forSellerOrder(SellerOrder $sellerOrder, bool $withHistory = false): array
    {
        $fulfilment = $this->quantities->forSellerOrder($sellerOrder);

        $items = array_values(array_map(
            static fn ($state): array => $state->toArray(),
            $fulfilment,
        ));

        $remaining = 0;

        foreach ($fulfilment as $state) {
            $remaining += $state->remainingToShip();
        }

        return [
            'items' => $items,
            'shipments' => $this->shipments($sellerOrder, $withHistory),
            'issues' => $this->issues($sellerOrder),
            'carriers' => Carriers::all(),
            'remainingUnits' => $remaining,
        ];
    }

    /**
     * The parcels, each with what is actually in it.
     *
     * Two queries: the shipments, and every line across all of them.
     *
     * @return array<int, array<string, mixed>>
     */
    private function shipments(SellerOrder $sellerOrder, bool $withHistory): array
    {
        /** @var Collection<int, Shipment> $shipments */
        $shipments = Shipment::query()
            ->where('seller_order_id', $sellerOrder->id)
            ->orderBy('sequence')
            ->get();

        if ($shipments->isEmpty()) {
            return [];
        }

        $lines = ShipmentItem::query()
            ->whereIn('shipment_id', $shipments->pluck('id'))
            ->with('orderItem')
            ->get()
            ->groupBy('shipment_id');

        $history = $withHistory
            ? ShipmentStatusHistory::query()
                ->whereIn('shipment_id', $shipments->pluck('id'))
                ->orderBy('created_at')
                ->orderBy('id')
                ->get()
                ->groupBy('shipment_id')
            : null;

        return $shipments
            ->map(static function (Shipment $shipment) use ($lines, $history): array {
                $row = [
                    'publicId' => $shipment->public_id,
                    'reference' => $shipment->reference,
                    'status' => $shipment->status->value,
                    'carrierName' => $shipment->carrier_name,
                    'carrierCode' => $shipment->carrier_code,
                    'trackingNumber' => $shipment->tracking_number,
                    'trackingUrl' => $shipment->tracking_url,
                    'shippedAt' => $shipment->shipped_at?->toIso8601String(),
                    'deliveredAt' => $shipment->delivered_at?->toIso8601String(),
                    'createdAt' => $shipment->created_at?->toIso8601String(),
                    'notes' => $shipment->notes,
                    'canShip' => $shipment->status->contentsAreMutable(),
                    'canDeliver' => $shipment->status->hasLeft()
                        && $shipment->status !== ShipmentStatus::Delivered,
                    'items' => ($lines[$shipment->id] ?? collect())
                        ->map(static fn (ShipmentItem $line): array => [
                            'orderItemId' => $line->order_item_id,
                            'title' => $line->orderItem->product_title,
                            'variantName' => $line->orderItem->variant_name,
                            'quantity' => $line->quantity,
                        ])
                        ->values()
                        ->all(),
                ];

                if ($history !== null) {
                    $row['history'] = ($history[$shipment->id] ?? collect())
                        ->map(static fn (ShipmentStatusHistory $entry): array => [
                            'from' => $entry->from_status?->value,
                            'to' => $entry->to_status->value,
                            'actorType' => $entry->actor_type,
                            'reason' => $entry->reason,
                            'carrierName' => $entry->carrier_name,
                            'trackingNumber' => $entry->tracking_number,
                            'at' => $entry->created_at->toIso8601String(),
                        ])
                        ->values()
                        ->all();
                }

                return $row;
            })
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function issues(SellerOrder $sellerOrder): array
    {
        return FulfilmentIssue::query()
            ->where('seller_order_id', $sellerOrder->id)
            ->orderByDesc('id')
            ->get()
            ->map(static fn (FulfilmentIssue $issue): array => [
                'publicId' => $issue->public_id,
                'reason' => $issue->reason->value,
                'note' => $issue->note,
                'reportedByType' => $issue->reported_by_type,
                'reportedAt' => $issue->created_at?->toIso8601String(),
                'resolvedAt' => $issue->resolved_at?->toIso8601String(),
                'resolutionNote' => $issue->resolution_note,
            ])
            ->all();
    }
}
