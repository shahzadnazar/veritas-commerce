<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Orders\Models\SellerOrder;
use App\Modules\Orders\Models\Shipment;

/**
 * The next parcel number for a seller order: VC-24081-01-S02.
 *
 * Readable on a packing slip and in a support call, which is the whole
 * reason it is not a ULID. The ULID remains the identity.
 *
 * `MAX(sequence) + 1` is only safe here because of two things that are
 * both required: the caller holds a row lock on the seller order, so two
 * requests cannot read the same maximum, and a unique index on
 * (seller_order_id, sequence) means that if the lock were ever missing the
 * second insert would fail loudly rather than duplicating a number a
 * customer has been given.
 */
final class AllocateShipmentReference
{
    /** @return array{sequence: int, reference: string} */
    public function __invoke(SellerOrder $sellerOrder): array
    {
        $highest = (int) Shipment::query()
            ->where('seller_order_id', $sellerOrder->id)
            ->max('sequence');

        $sequence = $highest + 1;

        return [
            'sequence' => $sequence,
            'reference' => sprintf('%s-S%02d', $sellerOrder->reference, $sequence),
        ];
    }
}
