<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Orders\Data\ShipmentTracking;
use App\Modules\Orders\Enums\SellerOrderStatus;
use App\Modules\Orders\Enums\ShipmentStatus;
use App\Modules\Orders\Events\ShipmentCreated;
use App\Modules\Orders\Exceptions\FulfilmentRefused;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\SellerOrder;
use App\Modules\Orders\Models\Shipment;
use App\Modules\Orders\Models\ShipmentItem;
use App\Modules\Orders\Models\ShipmentStatusHistory;
use App\Modules\Orders\Queries\FulfilmentQuantities;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Makes up a parcel, and says exactly what is in it.
 *
 * Every rule about whether a seller may put a unit in a box lives here,
 * not in the controller and not in React: the order must be paid, the
 * items must belong to it, and the quantities must be ones the seller
 * still owes the customer. A screen can offer whatever it likes; this
 * decides.
 *
 * The over-allocation guard is the interesting one. Two tabs pressing
 * "ship the last unit" at the same moment both read one remaining and both
 * proceed — unless something serialises them. Three things do, in order of
 * how much they can be trusted:
 *
 *  1. Each order item row is locked before its remaining quantity is read,
 *     so the second request waits and then reads the truth.
 *  2. `allocated_quantity` is incremented in the same transaction.
 *  3. A CHECK constraint refuses `allocated_quantity > quantity` outright,
 *     so even a future caller that forgot the lock cannot oversell a unit
 *     — it gets an error instead of a customer getting nothing.
 *
 * Refunded units are subtracted before any of that: a seller must not ship
 * something the customer has already had their money back for.
 */
final class CreateShipment
{
    public function __construct(
        private readonly FulfilmentQuantities $quantities,
        private readonly AllocateShipmentReference $references,
        private readonly AdvanceSellerOrder $advance,
    ) {}

    /**
     * @param  array<int, array{order_item_id: int, quantity: int}>  $lines
     *
     * @throws FulfilmentRefused
     */
    public function __invoke(
        SellerOrder $sellerOrder,
        array $lines,
        ?ShipmentTracking $tracking = null,
        string $actorType = 'seller',
        ?int $actorId = null,
        ?string $notes = null,
    ): Shipment {
        if ($lines === []) {
            throw FulfilmentRefused::nothingToShip();
        }

        $shipment = DB::transaction(function () use ($sellerOrder, $lines, $tracking, $actorType, $actorId, $notes): Shipment {
            /*
             * The seller order is locked first, and stays locked for the
             * whole transaction: it is what serialises two concurrent
             * shipments, and it is what makes MAX(sequence)+1 a safe way
             * to number them.
             */
            /** @var SellerOrder $locked */
            $locked = SellerOrder::query()
                ->withoutGlobalScopes()
                ->whereKey($sellerOrder->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->guardPayable($locked);

            $allocations = $this->allocate($locked, $lines);

            ['sequence' => $sequence, 'reference' => $reference] = ($this->references)($locked);

            /** @var Shipment $shipment */
            $shipment = Shipment::query()->create([
                'seller_order_id' => $locked->id,
                'reference' => $reference,
                'sequence' => $sequence,
                'status' => ShipmentStatus::Draft->value,
                'carrier_name' => $tracking?->carrierName,
                'carrier_code' => $tracking?->carrierCode,
                'tracking_number' => $tracking?->trackingNumber,
                'tracking_url' => $tracking?->url(),
                'created_by_type' => $actorType,
                'created_by_id' => $actorId,
                'notes' => $notes,
            ]);

            foreach ($allocations as $allocation) {
                ShipmentItem::query()->create([
                    'shipment_id' => $shipment->id,
                    'order_item_id' => $allocation['order_item_id'],
                    'quantity' => $allocation['quantity'],
                    'created_at' => now(),
                ]);

                /*
                 * The units leave the fulfilable pool now, while the
                 * parcel is still a draft. Waiting until it ships would
                 * let a second shipment claim the same unit in between.
                 */
                OrderItem::query()
                    ->whereKey($allocation['order_item_id'])
                    ->incrementEach(['allocated_quantity' => $allocation['quantity']]);
            }

            ShipmentStatusHistory::query()->create([
                'shipment_id' => $shipment->id,
                'from_status' => null,
                'to_status' => ShipmentStatus::Draft->value,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'carrier_name' => $shipment->carrier_name,
                'tracking_number' => $shipment->tracking_number,
                'created_at' => now(),
            ]);

            /*
             * Making up a parcel IS packing. The order moves to PACKED as
             * a consequence of a deliberate action rather than because a
             * screen was opened — which is the distinction §9 draws — and
             * an order already past packing is left where it is, because a
             * second parcel does not un-ship the first.
             */
            if (in_array($locked->status, [SellerOrderStatus::Confirmed, SellerOrderStatus::Processing], true)) {
                ($this->advance)($locked, SellerOrderStatus::Packed, actorType: $actorType, actorId: $actorId);
            }

            return $shipment;
        });

        $event = new ShipmentCreated(
            shipmentId: $shipment->id,
            shipmentReference: $shipment->reference,
            sellerOrderId: $sellerOrder->id,
            sellerAccountId: (int) $sellerOrder->seller_account_id,
        );

        // After commit, like every other announcement: a parcel that
        // rolled back was never made up.
        DB::afterCommit(static fn () => Event::dispatch($event));

        return $shipment;
    }

    /**
     * The payment boundary, as a guard rather than a hope.
     *
     * §2: an unpaid seller order is not actionable. A seller who packs
     * one either ships for nothing or learns to distrust the queue, and
     * both are worse than being told to wait.
     */
    private function guardPayable(SellerOrder $sellerOrder): void
    {
        if ($sellerOrder->status === SellerOrderStatus::PendingPayment) {
            throw FulfilmentRefused::notPaid();
        }

        if (! $sellerOrder->status->isActionable()) {
            throw FulfilmentRefused::invalidTransition($sellerOrder->status->value, 'shipped');
        }

        /*
         * Packing is not the first thing a seller does. Confirming is an
         * acknowledgement the customer's order has been accepted (§8), and
         * a parcel made up without it skips the only step that says a
         * person has looked.
         */
        if ($sellerOrder->status === SellerOrderStatus::Paid) {
            throw FulfilmentRefused::notConfirmed();
        }
    }

    /**
     * Turn requested lines into allocations the seller is actually owed.
     *
     * @param  array<int, array{order_item_id: int, quantity: int}>  $lines
     * @return array<int, array{order_item_id: int, quantity: int}>
     */
    private function allocate(SellerOrder $sellerOrder, array $lines): array
    {
        $ids = array_values(array_unique(array_map(
            static fn (array $line): int => (int) $line['order_item_id'],
            $lines,
        )));

        /*
         * Locked before their remaining quantities are read. This is the
         * step that makes the arithmetic below true at the moment it is
         * used rather than at the moment it was read.
         */
        $items = OrderItem::query()
            ->where('seller_order_id', $sellerOrder->id)
            ->whereIn('id', $ids)
            ->lockForUpdate()
            ->orderBy('id')
            ->get();

        if ($items->count() !== count($ids)) {
            // An item from another seller's order, or one that does not
            // exist. Either way it is not this seller's to send.
            throw FulfilmentRefused::notThisSellersItem();
        }

        $fulfilment = $this->quantities->forItems($items);
        $allocations = [];

        foreach ($lines as $line) {
            $itemId = (int) $line['order_item_id'];
            $quantity = (int) $line['quantity'];

            if ($quantity <= 0) {
                throw FulfilmentRefused::nothingToShip();
            }

            $state = $fulfilment[$itemId] ?? throw FulfilmentRefused::notThisSellersItem();
            $remaining = $state->remainingToShip();

            if ($quantity > $remaining) {
                throw FulfilmentRefused::exceedsRemaining($state->title, $remaining);
            }

            $allocations[] = ['order_item_id' => $itemId, 'quantity' => $quantity];
        }

        return $allocations;
    }
}
