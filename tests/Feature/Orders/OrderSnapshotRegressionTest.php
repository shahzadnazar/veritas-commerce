<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Commission\Models\CommissionRule;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Identity\Models\CustomerAddress;
use App\Modules\Identity\Models\User;
use App\Modules\Offers\Models\Offer;
use App\Modules\Sellers\Concerns\CurrentSeller;
use App\Modules\Sellers\Enums\SellerRole;
use App\Modules\Sellers\Models\SellerMembership;
use App\Modules\Stores\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsCommerceFixtures;
use Tests\TestCase;

/**
 * §27: an order still says what it said, after everything under it moves.
 *
 * The domain tests prove the snapshot columns hold their values. These
 * prove the three READ SURFACES actually use them — which is a different
 * claim, and the one that fails quietly. A page that joined to the live
 * offer to fetch a title would pass every database assertion in the suite
 * and still show a customer a receipt that had rewritten itself.
 *
 * So: place an order, move every underlying value, and read all three
 * screens back.
 */
final class OrderSnapshotRegressionTest extends TestCase
{
    use BuildsCommerceFixtures;
    use BuildsPlacedOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CommissionRule::factory()->create();
    }

    #[Test]
    public function every_order_surface_reads_the_snapshot_after_the_world_moves(): void
    {
        ['offer' => $offer, 'product' => $product, 'store' => $store, 'seller' => $seller]
            = $this->sellableOffer('Aeris Cordless Kettle', priceMinor: 9_900);

        $customer = User::factory()->create();
        ['user' => $sellerUser] = $this->attachOwner($seller->id);

        $order = $this->placeOrder([[$offer, 2]], $customer->id);

        // Captured before anything moves: `getOriginal()` after a save
        // returns the new value, so the only reliable "what it was" is
        // the one read while it still was.
        $originalStoreName = $store->name;
        $originalSku = $offer->seller_sku;

        $this->moveEverything($offer, $product, $store, $seller->id);

        // 1. The customer's receipt.
        $this->asUser($customer)->get('/account/orders/'.$order->reference)
            ->assertInertia(fn ($page) => $page
                ->where('order.sellerOrders.0.items.0.productTitle', 'Aeris Cordless Kettle')
                ->where('order.sellerOrders.0.items.0.storeName', $originalStoreName)
                ->where('order.sellerOrders.0.items.0.sellerSku', $originalSku)
                ->where('order.sellerOrders.0.items.0.unitPrice.minor', 9_900)
                ->where('order.grandTotal.minor', 19_800));

        // 2. The seller's packing view.
        $this->asUser($sellerUser)->get('/seller/orders/'.$order->reference.'-01')
            ->assertInertia(fn ($page) => $page
                ->where('sellerOrder.items.0.productTitle', 'Aeris Cordless Kettle')
                ->where('sellerOrder.items.0.sellerSku', $originalSku)
                ->where('sellerOrder.items.0.unitPrice.minor', 9_900)
                // The commission rate that applied on the day, not the
                // 30% the rule says today.
                ->where('sellerOrder.items.0.commissionRate', '12.00')
                ->where('sellerOrder.commissionTotal.minor', 2_376));

        // 3. The platform's inspection screen.
        $this->asAdmin($this->makeAdmin(AdminRole::FinanceAdmin))
            ->get('/admin/orders/'.$order->reference)
            ->assertInertia(fn ($page) => $page
                ->where('order.sellerOrders.0.items.0.productTitle', 'Aeris Cordless Kettle')
                ->where('order.sellerOrders.0.items.0.commissionRate', '12.00')
                ->where('order.sellerOrders.0.items.0.commission.minor', 2_376)
                ->where('order.sellerOrders.0.sellerEarningTotal.minor', 17_424));
    }

    #[Test]
    public function a_recategorised_product_does_not_repoint_a_historical_commission(): void
    {
        ['offer' => $offer, 'product' => $product] = $this->sellableOffer(priceMinor: 10_000);
        $customer = User::factory()->create();

        $order = $this->placeOrder([$offer], $customer->id);

        // A category-scoped rule at a different rate arrives afterwards,
        // and the product is moved into it.
        $category = Category::factory()->create();
        CommissionRule::factory()->create([
            'scope' => 'category',
            'category_id' => $category->id,
            'rate_percent' => '25.00',
        ]);
        $product->forceFill(['category_id' => $category->id])->save();

        $this->assertSame('12.00', (string) DB::table('order_items')->value('commission_rate_snapshot'));
        $this->assertSame(1_200, (int) DB::table('order_items')->value('commission_amount_minor'));

        $this->asUser($customer)->get('/account/orders/'.$order->reference)
            ->assertInertia(fn ($page) => $page->where('order.grandTotal.minor', 10_000));
    }

    #[Test]
    public function a_deleted_address_book_entry_does_not_change_where_an_order_went(): void
    {
        ['offer' => $offer] = $this->sellableOffer();
        $customer = User::factory()->create();
        $address = CustomerAddress::factory()->create([
            'user_id' => $customer->id,
            'line1' => '12 Analytical Way',
        ]);

        $order = $this->placeOrder([$offer], $customer->id);
        $address->delete();

        // The order holds its own copy; nothing downstream of checkout
        // keeps a foreign key to the address book.
        $this->asUser($customer)->get('/account/orders/'.$order->reference)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('order.shippingAddress.line1', '12 Analytical Way'));
    }

    /** Moves every value the order copied. */
    private function moveEverything(
        Offer $offer,
        Product $product,
        Store $store,
        int $sellerId,
    ): void {
        CurrentSeller::actingAs($sellerId, static function () use ($offer): void {
            $offer->forceFill(['price_minor' => 25_000, 'seller_sku' => 'REPLACED-SKU'])->save();
        });

        $product->forceFill(['title' => 'Renamed Kettle', 'slug' => 'renamed-kettle'])->save();
        $store->forceFill(['name' => 'Renamed Store'])->save();
        DB::table('commission_rules')->update(['rate_percent' => '30.00']);
    }

    /** @return array{user: User} */
    private function attachOwner(int $sellerId): array
    {
        $user = User::factory()->create();

        SellerMembership::factory()->create([
            'seller_account_id' => $sellerId,
            'user_id' => $user->id,
            'role' => SellerRole::Owner->value,
        ]);

        return ['user' => $user];
    }
}
