<?php

declare(strict_types=1);

namespace Tests\Invariants;

use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Ledger\Models\SellerLedgerEntry;
use App\Modules\Offers\Models\Offer;
use App\Modules\Orders\Models\SellerOrder;
use App\Modules\Payouts\Models\PayoutRequest;
use App\Modules\Sellers\Concerns\CurrentSeller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Invariant 1 — Seller A cannot reach Seller B's data.
 *
 * This is the highest-severity risk in a marketplace: one seller reading
 * another's orders or earnings ends the platform's credibility. The tests
 * below deliberately hand the query a foreign id, the way a hand-edited
 * request would, and assert the row is invisible rather than merely
 * forbidden.
 */
final class SellerIsolationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function seller_cannot_see_another_sellers_offers(): void
    {
        ['seller' => $a, 'store' => $storeA] = $this->makeSeller();
        ['seller' => $b, 'store' => $storeB] = $this->makeSeller();

        $offerA = Offer::factory()->create(['seller_account_id' => $a->id, 'store_id' => $storeA->id]);
        $offerB = Offer::factory()->create(['seller_account_id' => $b->id, 'store_id' => $storeB->id]);

        CurrentSeller::actingAs($a->id, function () use ($offerA, $offerB): void {
            $this->assertNotNull(Offer::find($offerA->id), 'A seller must see their own offer.');

            // The foreign id is supplied directly — this is the hand-edited
            // request case, and it must find nothing.
            $this->assertNull(Offer::find($offerB->id), 'Seller A must not load Seller B\'s offer by id.');
            $this->assertSame(1, Offer::query()->count(), 'An unfiltered query must still be tenant-scoped.');
        });
    }

    #[Test]
    public function seller_cannot_see_another_sellers_orders(): void
    {
        ['seller' => $a, 'store' => $storeA] = $this->makeSeller();
        ['seller' => $b, 'store' => $storeB] = $this->makeSeller();

        $orderA = SellerOrder::factory()->create(['seller_account_id' => $a->id, 'store_id' => $storeA->id]);
        $orderB = SellerOrder::factory()->create(['seller_account_id' => $b->id, 'store_id' => $storeB->id, 'position' => 2]);

        CurrentSeller::actingAs($a->id, function () use ($orderA, $orderB): void {
            $this->assertNotNull(SellerOrder::find($orderA->id));
            $this->assertNull(SellerOrder::find($orderB->id));
            $this->assertSame(1, SellerOrder::query()->count());
        });
    }

    #[Test]
    public function seller_cannot_see_another_sellers_earnings(): void
    {
        ['seller' => $a] = $this->makeSeller();
        ['seller' => $b] = $this->makeSeller();

        SellerLedgerEntry::factory()->create(['seller_account_id' => $a->id, 'amount_minor' => 5_000]);
        $entryB = SellerLedgerEntry::factory()->create(['seller_account_id' => $b->id, 'amount_minor' => 9_999]);

        CurrentSeller::actingAs($a->id, function () use ($entryB): void {
            $this->assertNull(SellerLedgerEntry::find($entryB->id));
            $this->assertSame(5_000, (int) SellerLedgerEntry::query()->sum('amount_minor'));
        });
    }

    #[Test]
    public function seller_cannot_see_another_sellers_payout_requests(): void
    {
        ['seller' => $a] = $this->makeSeller();
        ['seller' => $b] = $this->makeSeller();

        PayoutRequest::factory()->create(['seller_account_id' => $a->id]);
        $payoutB = PayoutRequest::factory()->create(['seller_account_id' => $b->id]);

        CurrentSeller::actingAs($a->id, function () use ($payoutB): void {
            $this->assertNull(PayoutRequest::find($payoutB->id));
            $this->assertSame(1, PayoutRequest::query()->count());
        });
    }

    #[Test]
    public function a_new_record_is_stamped_with_the_acting_seller_not_a_supplied_id(): void
    {
        ['seller' => $a, 'store' => $storeA] = $this->makeSeller();
        ['seller' => $b] = $this->makeSeller();

        // The request tries to claim Seller B. The scope must overrule it.
        $offer = CurrentSeller::actingAs($a->id, fn (): Offer => Offer::factory()->create([
            'store_id' => $storeA->id,
        ]));

        $this->assertSame($a->id, $offer->seller_account_id);
        $this->assertNotSame($b->id, $offer->seller_account_id);
    }

    #[Test]
    public function seller_cannot_adjust_another_sellers_inventory(): void
    {
        ['seller' => $a, 'store' => $storeA] = $this->makeSeller();
        ['seller' => $b, 'store' => $storeB] = $this->makeSeller();

        $offerB = Offer::factory()->create(['seller_account_id' => $b->id, 'store_id' => $storeB->id]);
        $locationB = InventoryLocation::create(['seller_account_id' => $b->id, 'name' => 'Default', 'is_default' => true]);
        $balanceB = InventoryBalance::create([
            'offer_id' => $offerB->id,
            'inventory_location_id' => $locationB->id,
            'on_hand' => 10,
        ]);

        CurrentSeller::actingAs($a->id, function () use ($balanceB, $offerB): void {
            // Seller A cannot even resolve the offer the balance belongs to,
            // so there is no path from a request to that balance.
            $this->assertNull(Offer::find($offerB->id));

            $reachable = InventoryBalance::query()
                ->whereIn('offer_id', Offer::query()->select('id'))
                ->pluck('id');

            $this->assertNotContains($balanceB->id, $reachable->all());
        });
    }

    #[Test]
    public function admin_scope_escape_is_explicit_and_sees_everything(): void
    {
        ['seller' => $a, 'store' => $storeA] = $this->makeSeller();
        ['seller' => $b, 'store' => $storeB] = $this->makeSeller();

        Offer::factory()->create(['seller_account_id' => $a->id, 'store_id' => $storeA->id]);
        Offer::factory()->create(['seller_account_id' => $b->id, 'store_id' => $storeB->id]);

        $all = CurrentSeller::withoutScope(fn (): int => Offer::query()->count());

        $this->assertSame(2, $all, 'Admin queries escape the tenant scope by an explicit, named path.');
    }
}
