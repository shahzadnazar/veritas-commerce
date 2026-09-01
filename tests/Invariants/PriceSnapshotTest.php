<?php

declare(strict_types=1);

namespace Tests\Invariants;

use App\Modules\Commission\Models\CommissionRule;
use App\Modules\Offers\Enums\OfferStatus;
use App\Modules\Offers\Models\Offer;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\SellerOrder;
use App\Modules\Sellers\Concerns\CurrentSeller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Invariant 3 — changing a seller's offer price never changes a historical
 * order item.
 *
 * A customer's receipt, a seller's statement and the platform's revenue all
 * have to still say what they said on the day. The order item carries its
 * own copy of the price, the title and the SKU precisely so that editing or
 * archiving a listing cannot rewrite them.
 */
final class PriceSnapshotTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function repricing_an_offer_does_not_change_a_placed_order_item(): void
    {
        CommissionRule::factory()->create();
        ['seller' => $seller, 'store' => $store] = $this->makeSeller();

        $offer = CurrentSeller::actingAs($seller->id, fn (): Offer => Offer::factory()
            ->priced(9_200)
            ->create(['store_id' => $store->id]));

        $sellerOrder = SellerOrder::factory()->create([
            'seller_account_id' => $seller->id,
            'store_id' => $store->id,
        ]);

        $item = OrderItem::factory()->create([
            'seller_order_id' => $sellerOrder->id,
            'offer_id' => $offer->id,
            'product_title' => 'Aeris cordless kettle, 1.2L',
            'seller_sku' => 'AC-KTL-1105',
            'unit_price_snapshot_minor' => 9_200,
            'quantity' => 1,
            'line_total_minor' => 9_200,
        ]);

        // The seller raises the price and retitles the listing.
        CurrentSeller::actingAs($seller->id, function () use ($offer): void {
            $offer->update(['price_minor' => 12_900]);
        });

        $item->refresh();

        $this->assertSame(9_200, $item->unit_price_snapshot_minor, 'The price the customer paid must not move.');
        $this->assertSame(9_200, $item->line_total_minor);
        $this->assertSame('Aeris cordless kettle, 1.2L', $item->product_title);
        $this->assertSame(12_900, $offer->refresh()->price_minor, 'The live offer is free to change.');
    }

    #[Test]
    public function archiving_an_offer_leaves_its_order_history_intact(): void
    {
        CommissionRule::factory()->create();
        ['seller' => $seller, 'store' => $store] = $this->makeSeller();

        $offer = CurrentSeller::actingAs($seller->id, fn (): Offer => Offer::factory()
            ->priced(7_600)
            ->create(['store_id' => $store->id]));

        $item = OrderItem::factory()->create([
            'seller_order_id' => SellerOrder::factory()->create([
                'seller_account_id' => $seller->id,
                'store_id' => $store->id,
            ])->id,
            'offer_id' => $offer->id,
            'unit_price_snapshot_minor' => 7_600,
            'line_total_minor' => 7_600,
        ]);

        // Lifecycle timestamps are set by domain actions, never by mass
        // assignment from a request — so they are not in $fillable.
        CurrentSeller::actingAs($seller->id, function () use ($offer): void {
            $offer->status = OfferStatus::Archived;
            $offer->archived_at = Carbon::now();
            $offer->save();
        });

        $item->refresh();

        $this->assertSame(7_600, $item->unit_price_snapshot_minor);
        $this->assertSame(
            $item->getOriginal('product_title'),
            $item->product_title,
            'The item describes itself from its own snapshot, without reading the offer.',
        );
    }

    #[Test]
    public function the_price_snapshot_cannot_be_updated_through_the_model(): void
    {
        CommissionRule::factory()->create();
        ['seller' => $seller, 'store' => $store] = $this->makeSeller();

        $item = OrderItem::factory()->create([
            'seller_order_id' => SellerOrder::factory()->create([
                'seller_account_id' => $seller->id,
                'store_id' => $store->id,
            ])->id,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('financial snapshot');

        $item->update(['unit_price_snapshot_minor' => 1]);
    }
}
