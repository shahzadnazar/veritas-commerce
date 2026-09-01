<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Actions\AdjustInventory;
use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Offers\Models\Offer;
use App\Modules\Sellers\Concerns\CurrentSeller;
use App\Modules\Sellers\Models\SellerAccount;
use App\Modules\Stores\Models\Store;

/**
 * One stocked offer, built the way the application builds one.
 *
 * Shared rather than copied into each inventory suite: a fixture that
 * drifts between files is how two tests end up proving different things
 * about the same rule.
 */
trait StocksOffers
{
    /**
     * @return array{seller: SellerAccount, store: Store, offer: Offer, location: InventoryLocation, balance: InventoryBalance}
     */
    protected function stockedOffer(int $onHand, ?SellerAccount $seller = null, ?Store $store = null): array
    {
        if ($seller === null) {
            ['seller' => $seller, 'store' => $store] = $this->makeSeller();
        }

        $store ??= Store::factory()->create(['seller_account_id' => $seller->id]);

        $offer = CurrentSeller::actingAs(
            $seller->id,
            fn (): Offer => Offer::factory()->create([
                'seller_account_id' => $seller->id,
                'store_id' => $store->id,
            ]),
        );

        $location = InventoryLocation::query()->firstOrCreate(
            ['seller_account_id' => $seller->id, 'is_default' => true],
            ['name' => 'Default'],
        );

        $balance = InventoryBalance::query()->create([
            'offer_id' => $offer->id,
            'inventory_location_id' => $location->id,
            'on_hand' => 0,
        ]);

        /*
         * Stock arrives the way the application makes it arrive.
         *
         * Writing `on_hand` straight onto the row was quicker and built a
         * state the application can never produce: a balance with no
         * movement explaining it, which `inventory:reconcile` correctly
         * rejects. A fixture that cannot reconcile is a fixture testing
         * something other than the system.
         */
        if ($onHand > 0) {
            app(AdjustInventory::class)->openingStock($offer, $onHand, 'seller', 1);
        }

        // Read back: `reserved` has a database default and `available` is
        // generated, so a freshly created model knows neither until it
        // asks. Returning it unrefreshed hands every caller a null.
        $balance->refresh();

        return [
            'seller' => $seller,
            'store' => $store,
            'offer' => $offer,
            'location' => $location,
            'balance' => $balance,
        ];
    }
}
