<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Offers\Models\Offer;
use Illuminate\Support\Facades\DB;

/**
 * The balance row for an offer, created on first use.
 *
 * Phase 1 gives each seller one location. The table has existed since M0 so
 * that multiple warehouses later are a data change rather than a migration
 * of every stock row, and this is the one place that assumption lives — so
 * when it stops being true, exactly one function has to learn about it.
 *
 * A balance appears at zero, not with stock: arriving at a count is
 * `EstablishOpeningStock`'s job, and it writes a movement for it.
 */
final class ResolveInventoryBalance
{
    public function __invoke(Offer $offer): InventoryBalance
    {
        return DB::transaction(function () use ($offer): InventoryBalance {
            $location = InventoryLocation::query()->firstOrCreate(
                ['seller_account_id' => $offer->seller_account_id, 'is_default' => true],
                ['name' => 'Default'],
            );

            $balance = InventoryBalance::query()->firstOrCreate(
                ['offer_id' => $offer->id, 'inventory_location_id' => $location->id],
                ['on_hand' => 0, 'reserved' => 0],
            );

            // `available` is generated and `reserved` has a database
            // default, so a row that was just created knows neither.
            return $balance->refresh();
        });
    }
}
