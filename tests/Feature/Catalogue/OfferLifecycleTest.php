<?php

declare(strict_types=1);

namespace Tests\Feature\Catalogue;

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Offers\Actions\SaveOffer;
use App\Modules\Offers\Actions\TransitionOffer;
use App\Modules\Offers\Enums\OfferCondition;
use App\Modules\Offers\Enums\OfferStatus;
use App\Modules\Offers\Models\Offer;
use App\Modules\Offers\Queries\OfferEligibility;
use App\Modules\Offers\Queries\OfferRankingService;
use App\Modules\Sellers\Enums\SellerStatus;
use App\Modules\Sellers\Models\SellerAccount;
use App\Modules\Stores\Models\Store;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The seller's commercial listing: what it may say, who may change it, and
 * when a customer sees it.
 */
final class OfferLifecycleTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_seller_lists_against_an_approved_product(): void
    {
        ['seller' => $seller] = $this->makeSeller();
        $product = Product::factory()->status(ProductStatus::Approved)->create();

        $offer = app(SaveOffer::class)($seller->id, $product, [
            'seller_sku' => 'AK-1200-BLK',
            'condition' => OfferCondition::New->value,
            'price_minor' => 4_999,
        ]);

        $this->assertSame($seller->id, $offer->seller_account_id);
        $this->assertSame($product->id, $offer->product_id);
        $this->assertSame(4_999, $offer->price_minor);
        // A new listing starts as a draft; going live is a separate act.
        $this->assertSame(OfferStatus::Draft, $offer->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'catalogue.offer.created']);
    }

    #[Test]
    public function money_is_stored_in_minor_units(): void
    {
        ['seller' => $seller] = $this->makeSeller();
        $product = Product::factory()->status(ProductStatus::Approved)->create();

        $offer = app(SaveOffer::class)($seller->id, $product, [
            'seller_sku' => 'AK-1',
            'condition' => OfferCondition::New->value,
            'price_minor' => 129_99,
        ]);

        // Integers, never floats: 129.99 as a float is not 129.99.
        $this->assertSame(12_999, $offer->price_minor);
        $this->assertSame(12_999, $offer->refresh()->price_minor, 'The value must survive the round trip exactly.');
        $this->assertSame('USD', $offer->currency);
    }

    #[Test]
    public function a_variant_specific_offer_names_a_variant_of_that_product(): void
    {
        ['seller' => $seller] = $this->makeSeller();
        $product = Product::factory()->status(ProductStatus::Approved)->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        $offer = app(SaveOffer::class)(
            $seller->id,
            $product,
            ['seller_sku' => 'AK-256', 'condition' => OfferCondition::New->value, 'price_minor' => 9_900],
            variant: $variant,
        );

        $this->assertSame($variant->id, $offer->product_variant_id);
    }

    #[Test]
    public function an_offer_cannot_name_a_variant_of_a_different_product(): void
    {
        ['seller' => $seller] = $this->makeSeller();
        $product = Product::factory()->status(ProductStatus::Approved)->create();
        $otherVariant = ProductVariant::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('belongs to a different product');

        app(SaveOffer::class)(
            $seller->id,
            $product,
            ['seller_sku' => 'AK-X', 'condition' => OfferCondition::New->value, 'price_minor' => 1_000],
            variant: $otherVariant,
        );
    }

    #[Test]
    public function the_database_refuses_a_mismatched_variant_too(): void
    {
        ['seller' => $seller, 'store' => $store] = $this->makeSeller();
        $product = Product::factory()->create();
        $otherVariant = ProductVariant::factory()->create();

        // A sale attributed to the wrong catalogue entry is not something
        // to leave to a check in PHP.
        $this->expectException(QueryException::class);

        app('db')->table('offers')->insert([
            'public_id' => (string) Str::ulid(),
            'seller_account_id' => $seller->id,
            'store_id' => $store->id,
            'product_id' => $product->id,
            'product_variant_id' => $otherVariant->id,
            'seller_sku' => 'MISMATCH',
            'condition' => 'new',
            'price_minor' => 1_000,
            'currency' => 'USD',
            'status' => 'draft',
            'handling_days' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function a_sku_is_unique_within_one_seller_but_free_across_sellers(): void
    {
        ['seller' => $first] = $this->makeSeller();
        ['seller' => $second] = $this->makeSeller();
        $productA = Product::factory()->status(ProductStatus::Approved)->create();
        $productB = Product::factory()->status(ProductStatus::Approved)->create();

        app(SaveOffer::class)($first->id, $productA, ['seller_sku' => 'SHARED-SKU', 'condition' => 'new', 'price_minor' => 1_000]);

        // Two sellers using the same internal code is normal and must not
        // collide: a SKU is the seller's own reference, not the
        // marketplace's.
        $offer = app(SaveOffer::class)($second->id, $productA, ['seller_sku' => 'SHARED-SKU', 'condition' => 'new', 'price_minor' => 1_100]);
        $this->assertSame($second->id, $offer->seller_account_id);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already use that SKU');

        app(SaveOffer::class)($first->id, $productB, ['seller_sku' => 'SHARED-SKU', 'condition' => 'new', 'price_minor' => 1_200]);
    }

    #[Test]
    public function a_seller_cannot_list_the_same_product_twice(): void
    {
        ['seller' => $seller] = $this->makeSeller();
        $product = Product::factory()->status(ProductStatus::Approved)->create();

        app(SaveOffer::class)($seller->id, $product, ['seller_sku' => 'A', 'condition' => 'new', 'price_minor' => 1_000]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already have a listing');

        app(SaveOffer::class)($seller->id, $product, ['seller_sku' => 'B', 'condition' => 'new', 'price_minor' => 900]);
    }

    #[Test]
    public function a_price_of_zero_or_less_is_refused_by_the_database(): void
    {
        $offer = Offer::factory()->create();

        $this->expectException(QueryException::class);

        app('db')->table('offers')->where('id', $offer->id)->update(['price_minor' => 0]);
    }

    #[Test]
    public function a_compare_at_price_below_the_price_is_refused(): void
    {
        ['seller' => $seller] = $this->makeSeller();
        $product = Product::factory()->status(ProductStatus::Approved)->create();

        // A compare-at price is a claim about a discount; below the price
        // it claims the opposite.
        $this->expectException(QueryException::class);

        app(SaveOffer::class)($seller->id, $product, [
            'seller_sku' => 'AK-2',
            'condition' => 'new',
            'price_minor' => 5_000,
            'compare_at_price_minor' => 4_000,
        ]);
    }

    #[Test]
    public function an_offer_cannot_be_listed_against_a_product_that_is_not_accepting_them(): void
    {
        ['seller' => $seller] = $this->makeSeller();
        $pending = Product::factory()->status(ProductStatus::PendingReview)->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not accepting offers');

        app(SaveOffer::class)($seller->id, $pending, ['seller_sku' => 'X', 'condition' => 'new', 'price_minor' => 1_000]);
    }

    #[Test]
    public function ownership_is_assigned_and_never_taken_from_the_request(): void
    {
        ['seller' => $mine] = $this->makeSeller();
        ['seller' => $theirs, 'store' => $theirStore] = $this->makeSeller();
        $product = Product::factory()->status(ProductStatus::Approved)->create();

        $offer = app(SaveOffer::class)($mine->id, $product, [
            'seller_sku' => 'AK-3',
            'condition' => 'new',
            'price_minor' => 2_000,
            // The attempt: a payload claiming to belong to someone else.
            'seller_account_id' => $theirs->id,
            'store_id' => $theirStore->id,
        ]);

        $this->assertSame($mine->id, $offer->seller_account_id);
        $this->assertNotSame($theirStore->id, $offer->store_id);
    }

    #[Test]
    public function a_suspended_seller_cannot_put_a_listing_live(): void
    {
        ['seller' => $seller] = $this->makeSeller();
        $product = Product::factory()->status(ProductStatus::Published)->create();
        $offer = app(SaveOffer::class)($seller->id, $product, ['seller_sku' => 'S', 'condition' => 'new', 'price_minor' => 3_000]);

        $seller->forceFill(['status' => SellerStatus::Suspended->value, 'suspended_at' => now()])->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('suspended seller cannot put a listing live');

        app(TransitionOffer::class)($offer->refresh(), OfferStatus::Published, 'seller', 1);
    }

    #[Test]
    public function an_offer_against_a_suspended_product_cannot_go_live(): void
    {
        ['seller' => $seller] = $this->makeSeller();
        $product = Product::factory()->status(ProductStatus::Approved)->create();
        $offer = app(SaveOffer::class)($seller->id, $product, ['seller_sku' => 'S2', 'condition' => 'new', 'price_minor' => 3_000]);

        $product->forceFill(['status' => ProductStatus::Suspended->value])->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not accepting offers');

        app(TransitionOffer::class)($offer->refresh(), OfferStatus::Published, 'seller', 1);
    }

    #[Test]
    public function eligibility_is_one_decision_used_everywhere(): void
    {
        $eligibility = app(OfferEligibility::class);
        $product = Product::factory()->status(ProductStatus::Published)->create();

        $visible = $this->publishedOffer($product);
        $this->assertTrue($eligibility->permits($visible->refresh()));
        $this->assertSame(1, $eligibility->query()->count());

        // Each of the five conditions, removed one at a time.
        $suspendedSeller = $this->publishedOffer($product);
        $suspendedSeller->sellerAccount?->forceFill(['status' => SellerStatus::Suspended->value])->save();

        $closedStore = $this->publishedOffer($product);
        $closedStore->store?->forceFill(['is_open' => false])->save();

        $draftOffer = $this->publishedOffer($product);
        $draftOffer->forceFill(['status' => OfferStatus::Draft->value])->save();

        $this->assertSame(1, $eligibility->query()->count(), 'Only the untouched offer stays visible.');
        $this->assertFalse($eligibility->permits($suspendedSeller->refresh()));
        $this->assertFalse($eligibility->permits($closedStore->refresh()));
        $this->assertFalse($eligibility->permits($draftOffer->refresh()));

        // And the fifth: suspending the product hides all of them at once.
        $product->forceFill(['status' => ProductStatus::Suspended->value])->save();
        $this->assertSame(0, $eligibility->query()->count());
        $this->assertFalse($eligibility->permits($visible->refresh()));
    }

    #[Test]
    public function the_query_and_the_single_offer_check_never_disagree(): void
    {
        $eligibility = app(OfferEligibility::class);
        $product = Product::factory()->status(ProductStatus::Published)->create();

        foreach (range(1, 4) as $ignored) {
            $this->publishedOffer($product);
        }

        Offer::query()->first()?->forceFill(['status' => OfferStatus::Suspended->value])->save();

        $fromQuery = $eligibility->query()->pluck('id')->sort()->values()->all();
        $fromCheck = Offer::query()->with(['product', 'sellerAccount', 'store'])->get()
            ->filter(fn (Offer $offer): bool => $eligibility->permits($offer))
            ->pluck('id')->sort()->values()->all();

        // A rule enforced in SQL and contradicted in PHP is worse than
        // either alone.
        $this->assertSame($fromQuery, $fromCheck);
    }

    #[Test]
    public function ranking_is_deterministic_and_cheapest_first(): void
    {
        $product = Product::factory()->status(ProductStatus::Published)->create();

        $expensive = $this->publishedOffer($product, 9_000);
        $cheap = $this->publishedOffer($product, 7_000);
        $middle = $this->publishedOffer($product, 8_000);

        $ranked = app(OfferRankingService::class)->rank(collect([$expensive, $cheap, $middle]));

        $this->assertSame([$cheap->id, $middle->id, $expensive->id], $ranked->pluck('id')->all());
        $this->assertSame($cheap->id, app(OfferRankingService::class)->featured(collect([$expensive, $cheap, $middle]))?->id);
    }

    #[Test]
    public function condition_breaks_a_price_tie(): void
    {
        $product = Product::factory()->status(ProductStatus::Published)->create();

        $used = $this->publishedOffer($product, 5_000, OfferCondition::UsedGood);
        $new = $this->publishedOffer($product, 5_000, OfferCondition::New);

        $ranked = app(OfferRankingService::class)->rank(collect([$used, $new]));

        $this->assertSame($new->id, $ranked->first()?->id, 'At the same price, new beats used.');
    }

    #[Test]
    public function the_same_offers_always_rank_the_same_way(): void
    {
        $product = Product::factory()->status(ProductStatus::Published)->create();

        $offers = collect(range(1, 5))->map(fn (): Offer => $this->publishedOffer($product, 5_000));

        $first = app(OfferRankingService::class)->rank($offers)->pluck('id')->all();
        $second = app(OfferRankingService::class)->rank($offers->shuffle())->pluck('id')->all();

        // Identical input, identical order — which is what makes the page
        // cacheable and a support question answerable.
        $this->assertSame($first, $second);
    }

    #[Test]
    public function a_price_range_spans_the_offers_on_a_product(): void
    {
        $product = Product::factory()->status(ProductStatus::Published)->create();

        $offers = collect([
            $this->publishedOffer($product, 11_750),
            $this->publishedOffer($product, 11_990),
            $this->publishedOffer($product, 12_200),
        ]);

        $this->assertSame(
            ['from' => 11_750, 'to' => 12_200],
            app(OfferRankingService::class)->priceRange($offers),
        );
        $this->assertNull(app(OfferRankingService::class)->priceRange(collect()));
    }

    private function publishedOffer(Product $product, ?int $priceMinor = null, ?OfferCondition $condition = null): Offer
    {
        $seller = SellerAccount::factory()->create();
        $store = Store::factory()->create(['seller_account_id' => $seller->id, 'is_open' => true]);

        return Offer::factory()->create([
            'seller_account_id' => $seller->id,
            'store_id' => $store->id,
            'product_id' => $product->id,
            'product_variant_id' => null,
            'status' => OfferStatus::Published->value,
            'price_minor' => $priceMinor ?? 5_000,
            'condition' => ($condition ?? OfferCondition::New)->value,
        ]);
    }
}
