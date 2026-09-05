<?php

declare(strict_types=1);

namespace App\Modules\Orders\Data;

/**
 * One order item's fulfilment arithmetic, computed in exactly one place.
 *
 * §64. These five numbers decide what a seller may put in a box, what the
 * customer is told is still coming, and when a seller order counts as
 * delivered. Working them out again in a controller, and a third time in
 * React, is how three screens end up disagreeing about the same order —
 * and how a seller ships a unit the customer was already refunded for.
 */
final readonly class ItemFulfilment
{
    public function __construct(
        public int $orderItemId,
        public string $publicId,
        public string $title,
        public ?string $variantName,
        public string $sku,
        /** What the customer bought. Never changes. */
        public int $ordered,
        /** Units returned to the customer as money, so no longer owed. */
        public int $refunded,
        /** Units committed to a live parcel, sent or not. */
        public int $allocated,
        /** Units in a parcel that has left the seller. */
        public int $shipped,
        /** Units in a parcel that arrived. */
        public int $delivered,
    ) {}

    /**
     * What the seller still owes the customer.
     *
     * Ordered, less what was refunded, less what is already in a parcel.
     * A unit sitting in a draft shipment is not available to a second
     * shipment, which is what stops two tabs sending the same item twice.
     */
    public function remainingToShip(): int
    {
        return max(0, $this->ordered - $this->refunded - $this->allocated);
    }

    /** Units the seller is still on the hook for, shipped or not. */
    public function fulfilable(): int
    {
        return max(0, $this->ordered - $this->refunded);
    }

    public function isFullyShipped(): bool
    {
        return $this->shipped >= $this->fulfilable();
    }

    public function isFullyDelivered(): bool
    {
        return $this->delivered >= $this->fulfilable();
    }

    /** Nothing left to do: everything was refunded before it shipped. */
    public function isFullyRefunded(): bool
    {
        return $this->fulfilable() === 0;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'orderItemId' => $this->orderItemId,
            'publicId' => $this->publicId,
            'title' => $this->title,
            'variantName' => $this->variantName,
            'sku' => $this->sku,
            'ordered' => $this->ordered,
            'refunded' => $this->refunded,
            'allocated' => $this->allocated,
            'shipped' => $this->shipped,
            'delivered' => $this->delivered,
            'remainingToShip' => $this->remainingToShip(),
            'fulfilable' => $this->fulfilable(),
        ];
    }
}
