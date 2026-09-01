<?php

declare(strict_types=1);

namespace Tests\Feature\Catalogue;

use App\Modules\Catalog\Actions\ResolveBrand;
use App\Modules\Catalog\Actions\SaveProductVariant;
use App\Modules\Catalog\Enums\AttributeType;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Exceptions\AttributeValidationFailed;
use App\Modules\Catalog\Models\Attribute;
use App\Modules\Catalog\Models\AttributeOption;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Queries\FindDuplicateProduct;
use App\Modules\Catalog\Support\CatalogueText;
use App\Modules\Catalog\Support\ProductSlug;
use App\Modules\Offers\Models\Offer;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The canonical product: its identifiers, its variants, its brand and its
 * address.
 */
final class CanonicalProductTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function one_canonical_product_carries_many_sellers_offers(): void
    {
        // The architecture in one assertion: three sellers listing the same
        // phone produce three offers against one catalogue entry, not three
        // near-identical entries nobody can compare.
        $product = Product::factory()->create(['title' => 'Aeris Cordless Kettle 1.2L']);

        foreach (range(1, 3) as $ignored) {
            ['seller' => $seller, 'store' => $store] = $this->makeSeller();

            Offer::factory()->create([
                'product_id' => $product->id,
                'seller_account_id' => $seller->id,
                'store_id' => $store->id,
            ]);
        }

        $this->assertSame(1, Product::query()->count());
        $this->assertSame(3, $product->offers()->count());
    }

    #[Test]
    public function a_trade_identifier_is_unique_across_the_catalogue(): void
    {
        Product::factory()->create(['ean' => '9780306406157']);

        // The database is the guarantee, not a check in a form.
        $this->expectException(QueryException::class);

        Product::factory()->create(['ean' => '9780306406157']);
    }

    #[Test]
    public function products_without_identifiers_do_not_collide(): void
    {
        // A handmade bowl has no barcode, and requiring one would exclude
        // exactly the sellers a marketplace wants.
        Product::factory()->count(3)->create(['gtin' => null, 'upc' => null, 'ean' => null]);

        $this->assertSame(3, Product::query()->count());
    }

    #[Test]
    public function a_matching_barcode_is_a_conclusive_duplicate(): void
    {
        $existing = Product::factory()->create([
            'title' => 'Aeris Cordless Kettle',
            'ean' => '9780306406157',
        ]);

        $matches = app(FindDuplicateProduct::class)(
            title: 'Completely Different Name',
            brandId: null,
            categoryId: null,
            identifiers: ['ean' => '978-0-306-40615-7'],
        );

        $this->assertCount(1, $matches);
        $this->assertSame('ean', $matches[0]->signal);
        $this->assertTrue($matches[0]->isConclusive, 'A shared barcode means the same product.');
        $this->assertSame($existing->id, $matches[0]->product->id);
    }

    #[Test]
    public function a_matching_title_is_suggestive_but_never_conclusive(): void
    {
        $category = Category::factory()->create();
        $brand = Brand::factory()->create();

        Product::factory()->create([
            'title' => 'Cotton Tote Bag',
            'normalised_title' => CatalogueText::normalise('Cotton Tote Bag'),
            'category_id' => $category->id,
            'brand_id' => $brand->id,
        ]);

        $matches = app(FindDuplicateProduct::class)(
            title: 'cotton  tote bag',
            brandId: $brand->id,
            categoryId: $category->id,
        );

        $this->assertCount(1, $matches);
        $this->assertSame('title', $matches[0]->signal);
        // Two sellers may genuinely stock different things with the same
        // plain name, so this goes to a person rather than being refused.
        $this->assertFalse($matches[0]->isConclusive);
        $this->assertNull(app(FindDuplicateProduct::class)->conclusiveIn($matches));
    }

    #[Test]
    public function brand_and_part_number_together_are_conclusive(): void
    {
        $brand = Brand::factory()->create();
        $existing = Product::factory()->create(['brand_id' => $brand->id, 'mpn' => 'AK-1200-BLK']);

        $matches = app(FindDuplicateProduct::class)(
            title: 'Something Else Entirely',
            brandId: $brand->id,
            categoryId: null,
            identifiers: ['mpn' => 'AK-1200-BLK'],
        );

        $this->assertSame('brand_model', $matches[0]->signal);
        $this->assertSame($existing->id, app(FindDuplicateProduct::class)->conclusiveIn($matches)?->product->id);
    }

    #[Test]
    public function a_merged_product_is_never_offered_as_a_duplicate_candidate(): void
    {
        $survivor = Product::factory()->create(['ean' => null]);
        $merged = Product::factory()->create(['ean' => '9780306406157']);
        $merged->forceFill([
            'merged_into_product_id' => $survivor->id,
            'merged_at' => now(),
        ])->save();

        // The survivor is the candidate, not the record it replaced.
        $matches = app(FindDuplicateProduct::class)(
            title: 'Anything',
            brandId: null,
            categoryId: null,
            identifiers: ['ean' => '9780306406157'],
        );

        $this->assertSame([], $matches);
    }

    #[Test]
    public function a_product_cannot_be_merged_into_itself(): void
    {
        $product = Product::factory()->create();

        $this->expectException(QueryException::class);

        app('db')->table('products')->where('id', $product->id)->update([
            'merged_into_product_id' => $product->id,
            'merged_at' => now(),
        ]);
    }

    #[Test]
    public function a_merge_must_record_when_it_happened(): void
    {
        $survivor = Product::factory()->create();
        $merged = Product::factory()->create();

        // A pointer with no date is a merge nobody can audit.
        $this->expectException(QueryException::class);

        app('db')->table('products')->where('id', $merged->id)
            ->update(['merged_into_product_id' => $survivor->id]);
    }

    #[Test]
    public function a_merged_product_stops_resolving_publicly_but_keeps_its_offers(): void
    {
        ['seller' => $seller, 'store' => $store] = $this->makeSeller();
        $survivor = Product::factory()->create();
        $merged = Product::factory()->create();

        Offer::factory()->create([
            'product_id' => $merged->id,
            'seller_account_id' => $seller->id,
            'store_id' => $store->id,
        ]);

        $merged->forceFill(['merged_into_product_id' => $survivor->id, 'merged_at' => now()])->save();

        $this->assertFalse($merged->refresh()->isPubliclyVisible());
        // Nothing is deleted: the offers, the history and the media all
        // survive so a merge can be undone and audited.
        $this->assertSame(1, $merged->offers()->count());
        $this->assertSame($survivor->id, $merged->mergedInto?->id);
        $this->assertSame(1, Product::query()->published()->count());
    }

    #[Test]
    public function a_variant_is_a_point_in_the_categorys_variant_axes(): void
    {
        ['product' => $product] = $this->variantSchema();

        $variant = app(SaveProductVariant::class)($product, ['colour' => 'black', 'capacity' => '256']);

        $this->assertSame('Black / 256', $variant->name);
        $this->assertSame('capacity=256;colour=black', $variant->option_signature);

        // The axes are also stored as queryable attribute rows, not only
        // as a label.
        $this->assertDatabaseHas('product_attribute_values', [
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
        ]);
    }

    #[Test]
    public function the_same_combination_cannot_exist_twice(): void
    {
        ['product' => $product] = $this->variantSchema();

        app(SaveProductVariant::class)($product, ['colour' => 'black', 'capacity' => '256']);

        // Order must not matter: it is the same point either way.
        $this->expectException(AttributeValidationFailed::class);
        $this->expectExceptionMessage('already has a variant');

        app(SaveProductVariant::class)($product, ['capacity' => '256', 'colour' => 'black']);
    }

    #[Test]
    public function the_database_refuses_a_duplicate_combination_too(): void
    {
        ['product' => $product] = $this->variantSchema();
        app(SaveProductVariant::class)($product, ['colour' => 'black', 'capacity' => '256']);

        $this->expectException(QueryException::class);

        app('db')->table('product_variants')->insert([
            'public_id' => (string) Str::ulid(),
            'product_id' => $product->id,
            'name' => 'Black / 256',
            'option_signature' => 'capacity=256;colour=black',
            'position' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function a_variant_must_state_every_axis(): void
    {
        ['product' => $product] = $this->variantSchema();

        $this->expectException(AttributeValidationFailed::class);
        $this->expectExceptionMessage('every variant must state it');

        app(SaveProductVariant::class)($product, ['colour' => 'black']);
    }

    #[Test]
    public function a_value_outside_the_axis_is_refused(): void
    {
        ['product' => $product] = $this->variantSchema();

        $this->expectException(AttributeValidationFailed::class);

        app(SaveProductVariant::class)($product, ['colour' => 'chartreuse', 'capacity' => '256']);
    }

    #[Test]
    public function an_attribute_that_does_not_vary_belongs_on_the_product(): void
    {
        ['product' => $product] = $this->variantSchema();

        $this->expectException(AttributeValidationFailed::class);
        $this->expectExceptionMessage('does not vary in this category');

        app(SaveProductVariant::class)($product, [
            'colour' => 'black',
            'capacity' => '256',
            'warranty' => '2 years',
        ]);
    }

    #[Test]
    public function a_variant_belongs_to_exactly_one_product(): void
    {
        ['product' => $first] = $this->variantSchema();
        $second = Product::factory()->create(['category_id' => $first->category_id]);

        $variant = app(SaveProductVariant::class)($first, ['colour' => 'black', 'capacity' => '256']);

        $this->assertSame($first->id, $variant->product_id);
        $this->assertSame(0, ProductVariant::query()->where('product_id', $second->id)->count());

        // The same coordinate on a different product is a different
        // variant, and perfectly legal.
        $secondVariant = app(SaveProductVariant::class)($second, ['colour' => 'black', 'capacity' => '256']);
        $this->assertSame($second->id, $secondVariant->product_id);
    }

    #[Test]
    public function brand_names_that_differ_only_in_case_or_spacing_are_one_brand(): void
    {
        ['seller' => $seller] = $this->makeSeller();
        $resolver = app(ResolveBrand::class);

        $first = $resolver->propose('Aeris', $seller->id);
        $second = $resolver->propose('  AERIS  ', $seller->id);

        $this->assertSame($first->id, $second->id, 'Apple, APPLE and apple are one brand.');
        $this->assertSame(1, Brand::query()->count());
    }

    #[Test]
    public function the_database_refuses_a_second_brand_with_the_same_normalised_name(): void
    {
        Brand::factory()->create(['name' => 'Aeris', 'normalised_name' => 'aeris']);

        $this->expectException(QueryException::class);

        Brand::factory()->create(['name' => 'AERIS', 'normalised_name' => 'aeris']);
    }

    #[Test]
    public function a_seller_proposed_brand_is_not_live_until_a_moderator_approves_it(): void
    {
        ['seller' => $seller] = $this->makeSeller();

        $brand = app(ResolveBrand::class)->propose('Northline Audio', $seller->id);

        $this->assertFalse($brand->isApproved(), 'A seller cannot mint a live marketplace brand.');
        $this->assertSame($seller->id, $brand->proposed_by_seller_account_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'catalogue.brand.proposed']);

        app(ResolveBrand::class)->approve($brand, 1);

        $this->assertTrue($brand->refresh()->isApproved());
        $this->assertDatabaseHas('audit_logs', ['action' => 'catalogue.brand.approved']);
    }

    #[Test]
    public function a_product_slug_comes_from_its_title_and_never_from_its_id(): void
    {
        $slug = ProductSlug::unique('Aeris Cordless Kettle 1.2L');

        $this->assertSame('aeris-cordless-kettle-1-2l', $slug);
    }

    #[Test]
    public function a_taken_slug_is_suffixed_rather_than_reused(): void
    {
        Product::factory()->create(['slug' => 'cotton-tote-bag']);

        $this->assertSame('cotton-tote-bag-2', ProductSlug::unique('Cotton Tote Bag'));
    }

    #[Test]
    public function a_retired_slug_is_not_handed_to_another_product(): void
    {
        $product = Product::factory()->create(['slug' => 'new-address']);
        app('db')->table('product_slug_history')->insert([
            'product_id' => $product->id,
            'old_slug' => 'old-address',
            'changed_at' => now(),
        ]);

        // Reusing it would redirect one product's accumulated traffic to a
        // stranger.
        $this->assertSame('old-address-2', ProductSlug::unique('Old Address'));
    }

    #[Test]
    public function a_reserved_word_cannot_become_a_product_address(): void
    {
        foreach (['new', 'search', 'compare'] as $reserved) {
            $this->assertNotSame($reserved, ProductSlug::unique($reserved));
        }
    }

    #[Test]
    public function moderation_status_and_public_visibility_are_not_the_same_thing(): void
    {
        $this->assertTrue(ProductStatus::Published->isPublic());
        $this->assertFalse(ProductStatus::Approved->isPublic(), 'Approved is in the catalogue, not on the storefront.');

        // Both accept offers; only one is publicly visible.
        $this->assertTrue(ProductStatus::Approved->acceptsOffers());
        $this->assertTrue(ProductStatus::Published->acceptsOffers());
        $this->assertFalse(ProductStatus::Suspended->acceptsOffers());
        $this->assertFalse(ProductStatus::PendingReview->acceptsOffers());
    }

    /** @return array{product: Product, category: Category} */
    private function variantSchema(): array
    {
        $category = Category::factory()->create(['name' => 'Phones']);

        $colour = Attribute::factory()->variantDefining()->create(['code' => 'colour', 'name' => 'Colour']);
        foreach (['black' => 'Black', 'white' => 'White'] as $value => $label) {
            AttributeOption::factory()->create(['attribute_id' => $colour->id, 'value' => $value, 'label' => $label]);
        }

        $capacity = Attribute::factory()
            ->ofType(AttributeType::Integer)
            ->create(['code' => 'capacity', 'name' => 'Capacity', 'unit' => 'GB', 'is_variant_defining' => true]);

        $warranty = Attribute::factory()->create(['code' => 'warranty', 'name' => 'Warranty']);

        $category->attributes()->attach($colour->id, ['is_variant_defining' => true]);
        $category->attributes()->attach($capacity->id, ['is_variant_defining' => true]);
        $category->attributes()->attach($warranty->id, ['is_variant_defining' => false]);

        return [
            'product' => Product::factory()->create(['category_id' => $category->id]),
            'category' => $category,
        ];
    }
}
