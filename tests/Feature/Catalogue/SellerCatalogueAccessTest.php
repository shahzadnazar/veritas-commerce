<?php

declare(strict_types=1);

namespace Tests\Feature\Catalogue;

use App\Modules\Catalog\Enums\AttributeType;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Attribute;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Offers\Enums\OfferStatus;
use App\Modules\Offers\Models\Offer;
use App\Modules\Sellers\Enums\SellerRole;
use App\Modules\Sellers\Enums\SellerStatus;
use App\Modules\Sellers\Models\SellerAccount;
use App\Modules\Stores\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The seller catalogue over HTTP, with real ids.
 *
 * Every isolation case here posts another seller's public id from a
 * signed-in session. Asserting that a link was hidden proves nothing: the
 * question is what the application does when the request arrives anyway.
 */
final class SellerCatalogueAccessTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_seller_searches_the_canonical_catalogue_before_proposing_anything(): void
    {
        ['user' => $user] = $this->makeSeller();

        Product::factory()->create(['title' => 'Aeris Cordless Kettle 1.2L']);
        Product::factory()->create(['title' => 'Something Else Entirely']);

        $this->asUser($user)
            ->get('/seller/products?search=Aeris')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Catalogue/Search')
                ->has('matches', 1)
                ->where('matches.0.title', 'Aeris Cordless Kettle 1.2L'));
    }

    #[Test]
    public function search_finds_a_product_by_its_barcode_as_well_as_its_name(): void
    {
        ['user' => $user] = $this->makeSeller();

        Product::factory()->create(['title' => 'Aeris Kettle', 'gtin' => '00012345678905']);

        $this->asUser($user)
            ->get('/seller/products?search=00012345678905')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('matches', 1));
    }

    #[Test]
    public function the_search_page_never_shows_a_product_that_is_not_accepting_offers(): void
    {
        ['user' => $user] = $this->makeSeller();

        Product::factory()->status(ProductStatus::PendingReview)->create(['title' => 'Aeris Draft Kettle']);

        $this->asUser($user)
            ->get('/seller/products?search=Aeris')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('matches', 0));
    }

    #[Test]
    public function a_seller_proposes_a_product_the_catalogue_does_not_have(): void
    {
        ['seller' => $seller, 'user' => $user] = $this->makeSeller();
        $category = $this->kettleCategory();

        $this->asUser($user)
            ->post('/seller/products', [
                'title' => 'Aeris Cordless Kettle 1.2L',
                'category_id' => $category->id,
                'specifications' => ['capacity' => '1200'],
            ])
            ->assertRedirect('/seller/products');

        $product = Product::query()->where('title', 'Aeris Cordless Kettle 1.2L')->firstOrFail();

        $this->assertSame(ProductStatus::PendingReview, $product->status);
        $this->assertSame($seller->id, $product->created_by_seller_account_id);
    }

    #[Test]
    public function the_propose_form_shows_what_the_catalogue_already_holds(): void
    {
        ['user' => $user] = $this->makeSeller();
        $existing = Product::factory()->create(['title' => 'Aeris Cordless Kettle', 'gtin' => '00012345678905']);

        $this->asUser($user)
            ->get('/seller/products/create?title=Aeris+Cordless+Kettle')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Catalogue/Propose')
                ->has('likelyDuplicates', 1)
                ->where('likelyDuplicates.0.publicId', $existing->public_id));
    }

    #[Test]
    public function a_seller_corrects_their_own_proposal_and_it_returns_to_the_queue(): void
    {
        ['seller' => $seller, 'user' => $user] = $this->makeSeller();
        $category = $this->kettleCategory();

        $product = Product::factory()
            ->proposedBy($seller->id)
            ->create(['category_id' => $category->id, 'title' => 'Aeris Ketle']);

        $product->forceFill([
            'status' => ProductStatus::ChangesRequested->value,
            'moderation_reason' => 'The name is misspelt.',
        ])->save();

        $this->asUser($user)
            ->patch("/seller/products/{$product->public_id}", [
                'title' => 'Aeris Kettle',
                'category_id' => $category->id,
                'specifications' => ['capacity' => '1200'],
            ])
            ->assertRedirect('/seller/products');

        $product->refresh();

        $this->assertSame('Aeris Kettle', $product->title);
        $this->assertSame(ProductStatus::PendingReview, $product->status);
    }

    #[Test]
    public function a_seller_cannot_edit_a_product_the_catalogue_has_already_accepted(): void
    {
        ['seller' => $seller, 'user' => $user] = $this->makeSeller();
        $category = $this->kettleCategory();

        // Their own proposal — but approved, so it is now shared with
        // every other seller listing against it.
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'created_by_seller_account_id' => $seller->id,
            'title' => 'Aeris Kettle',
        ]);

        $this->asUser($user)
            ->patch("/seller/products/{$product->public_id}", [
                'title' => 'Aeris Kettle — Best Price Guaranteed!',
                'category_id' => $category->id,
            ])
            ->assertForbidden();

        $this->assertSame('Aeris Kettle', $product->refresh()->title);
    }

    #[Test]
    public function a_seller_cannot_edit_a_proposal_another_seller_made(): void
    {
        ['user' => $intruder] = $this->makeSeller();
        $victim = SellerAccount::factory()->create();

        $product = Product::factory()->proposedBy($victim->id)->create(['title' => 'Their Kettle']);
        $product->forceFill(['status' => ProductStatus::ChangesRequested->value])->save();

        // A real, resolvable public id — just not theirs.
        $this->asUser($intruder)
            ->patch("/seller/products/{$product->public_id}", [
                'title' => 'Mine Now',
                'category_id' => $product->category_id,
            ])
            ->assertNotFound();

        $this->assertSame('Their Kettle', $product->refresh()->title);
    }

    #[Test]
    public function a_seller_cannot_mutate_canonical_identity_through_an_offer_update(): void
    {
        ['seller' => $seller, 'user' => $user] = $this->makeSeller();
        $product = Product::factory()->create(['title' => 'Aeris Kettle', 'gtin' => '00012345678905']);
        $offer = Offer::factory()->forSeller($seller)->create(['product_id' => $product->id]);

        $this->asUser($user)
            ->patch("/seller/offers/{$offer->public_id}", [
                'seller_sku' => 'SKU-1',
                'condition' => 'new',
                'price_minor' => 4999,
                'handling_days' => 1,
                // Canonical fields smuggled into an offer payload.
                'title' => 'Aeris Kettle (Genuine, Fast Ship)',
                'gtin' => '00099999999999',
                'category_id' => 999,
                'status' => ProductStatus::Suspended->value,
            ])
            ->assertRedirect();

        $product->refresh();

        $this->assertSame('Aeris Kettle', $product->title);
        $this->assertSame('00012345678905', $product->gtin);
        $this->assertSame(ProductStatus::Published, $product->status);
    }

    #[Test]
    public function a_seller_cannot_reach_another_sellers_offer_management_route(): void
    {
        ['user' => $intruder] = $this->makeSeller();

        $victim = SellerAccount::factory()->create();
        $victimStore = Store::factory()->create(['seller_account_id' => $victim->id]);
        $offer = Offer::factory()->forSeller($victim, $victimStore)->create(['seller_sku' => 'THEIRS-1']);

        $this->asUser($intruder)
            ->patch("/seller/offers/{$offer->public_id}", [
                'seller_sku' => 'MINE-1',
                'condition' => 'new',
                'price_minor' => 100,
                'handling_days' => 1,
            ])
            ->assertNotFound();

        $this->asUser($intruder)
            ->post("/seller/offers/{$offer->public_id}/status", ['status' => OfferStatus::Suspended->value])
            ->assertNotFound();

        $offer->refresh();

        $this->assertSame('THEIRS-1', $offer->seller_sku);
        $this->assertSame(OfferStatus::Published, $offer->status);
    }

    #[Test]
    public function ownership_comes_from_the_session_not_from_the_payload(): void
    {
        ['seller' => $seller, 'user' => $user] = $this->makeSeller();
        $other = SellerAccount::factory()->create();
        $otherStore = Store::factory()->create(['seller_account_id' => $other->id]);
        $product = Product::factory()->create();

        $this->asUser($user)
            ->post("/seller/offers/{$product->public_id}", [
                'seller_sku' => 'SKU-CRAFTED',
                'condition' => 'new',
                'price_minor' => 2500,
                'handling_days' => 1,
                // The two ids a crafted request would most like to decide.
                'seller_account_id' => $other->id,
                'store_id' => $otherStore->id,
            ])
            ->assertRedirect();

        $offer = Offer::query()->where('seller_sku', 'SKU-CRAFTED')->firstOrFail();

        $this->assertSame($seller->id, $offer->seller_account_id);
        $this->assertNotSame($other->id, $offer->seller_account_id);
        $this->assertNotSame($otherStore->id, $offer->store_id);
    }

    #[Test]
    public function a_seller_cannot_list_against_a_variant_of_a_different_product(): void
    {
        ['user' => $user] = $this->makeSeller();

        $product = Product::factory()->create();
        $otherOffer = Offer::factory()->create();

        $this->asUser($user)
            ->post("/seller/offers/{$product->public_id}", [
                'seller_sku' => 'SKU-X',
                'condition' => 'new',
                'price_minor' => 2500,
                'handling_days' => 1,
                'variant_public_id' => $otherOffer->productVariant?->public_id,
            ])
            ->assertNotFound();
    }

    #[Test]
    public function a_suspended_seller_cannot_publish_a_listing_over_http(): void
    {
        ['seller' => $seller, 'user' => $user] = $this->makeSeller();
        $offer = Offer::factory()->forSeller($seller)->draft()->create();

        $seller->forceFill(['status' => SellerStatus::Suspended->value])->save();

        // Refused at the door: a suspended seller holds no write
        // capability at all, so this never reaches the offer's own guard.
        // That guard is tested directly in OfferLifecycleTest, because a
        // second line of defence is only worth having if it is exercised.
        $this->asUser($user)
            ->post("/seller/offers/{$offer->public_id}/status", ['status' => OfferStatus::Published->value])
            ->assertForbidden();

        $this->assertSame(OfferStatus::Draft, $offer->refresh()->status);
    }

    #[Test]
    public function a_seller_role_without_catalogue_management_can_look_but_not_list(): void
    {
        // A viewer reads the catalogue; only a catalogue manager lists.
        ['user' => $user] = $this->makeSeller(SellerRole::Viewer);
        $product = Product::factory()->create();

        $this->asUser($user)->get('/seller/offers')->assertOk();

        $this->asUser($user)
            ->post("/seller/offers/{$product->public_id}", [
                'seller_sku' => 'SKU-NOPE',
                'condition' => 'new',
                'price_minor' => 100,
                'handling_days' => 1,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('offers', ['seller_sku' => 'SKU-NOPE']);
    }

    #[Test]
    public function the_offer_list_costs_the_same_whether_a_seller_has_one_listing_or_twenty(): void
    {
        ['seller' => $seller, 'user' => $user] = $this->makeSeller();

        Offer::factory()->forSeller($seller)->create();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->asUser($user)->get('/seller/offers')->assertOk();
        $one = count(DB::getQueryLog());
        DB::disableQueryLog();

        Offer::factory()->count(19)->forSeller($seller)->create();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->asUser($user)->get('/seller/offers')->assertOk();
        $twenty = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Eager loading, not a query per row: nineteen more listings must
        // not be nineteen more round trips for the product and variant.
        $this->assertSame(
            $one,
            $twenty,
            "Listing 20 offers took {$twenty} queries against {$one} for a single offer — an N+1.",
        );
    }

    private function kettleCategory(): Category
    {
        $category = Category::factory()->create(['name' => 'Kettles', 'is_visible' => true]);

        $capacity = Attribute::factory()
            ->ofType(AttributeType::Integer)
            ->create(['code' => 'capacity', 'name' => 'Capacity', 'unit' => 'ml']);

        $category->attributes()->attach($capacity->id, ['is_required' => true]);

        return $category->refresh();
    }
}
