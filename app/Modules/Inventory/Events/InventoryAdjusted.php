<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Events;

use App\Modules\Inventory\Enums\StockState;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Stock for one offer changed.
 *
 * Carries ids and the before/after state rather than models, so a queued
 * listener cannot resurrect a stale balance — and so a listener deciding
 * whether to notify does not have to re-derive which side of the threshold
 * the offer was on before.
 */
final class InventoryAdjusted
{
    use Dispatchable;

    public function __construct(
        public readonly int $offerId,
        public readonly int $productId,
        public readonly int $sellerAccountId,
        public readonly StockState $from,
        public readonly StockState $to,
        public readonly int $available,
    ) {}

    public function stateChanged(): bool
    {
        return $this->from !== $this->to;
    }
}
