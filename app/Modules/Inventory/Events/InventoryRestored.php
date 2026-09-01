<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * An offer is buyable again after being low or empty.
 *
 * Raised on the transition, never on the level: something that fired while
 * stock merely *is* low would email the seller on every save.
 */
final class InventoryRestored
{
    use Dispatchable;

    public function __construct(
        public readonly int $offerId,
        public readonly int $sellerAccountId,
        public readonly int $available,
    ) {}
}
