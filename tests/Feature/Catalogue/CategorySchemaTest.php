<?php

declare(strict_types=1);

namespace Tests\Feature\Catalogue;

use App\Modules\Catalog\Actions\SaveAttributeValues;
use App\Modules\Catalog\Actions\SaveCategory;
use App\Modules\Catalog\Actions\TransitionProduct;
use App\Modules\Catalog\Enums\AttributeType;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Exceptions\AttributeValidationFailed;
use App\Modules\Catalog\Models\Attribute;
use App\Modules\Catalog\Models\AttributeOption;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Identity\Models\AdminUser;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The category tree, and the attribute schema hanging off it.
 */
final class CategorySchemaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_category_tree_records_its_depth_and_its_ancestry(): void
    {
        $electronics = $this->category('Electronics');
        $phones = $this->category('Mobile Phones', $electronics);
        $smartphones = $this->category('Smartphones', $phones);

        $this->assertSame(0, $electronics->depth);
        $this->assertSame(1, $phones->depth);
        $this->assertSame(2, $smartphones->depth);

        // The path is the breadcrumb: one read, no recursive walk.
        $this->assertSame(
            [$electronics->id, $phones->id, $smartphones->id],
            $smartphones->ancestorIds(),
        );
    }

    #[Test]
    public function moving_a_subtree_repaths_everything_under_it(): void
    {
        $electronics = $this->category('Electronics');
        $phones = $this->category('Mobile Phones', $electronics);
        $smartphones = $this->category('Smartphones', $phones);
        $refurbished = $this->category('Refurbished');

        app(SaveCategory::class)($phones, ['parent_id' => $refurbished->id], 'admin', 1);

        $this->assertSame([$refurbished->id, $phones->id], $phones->refresh()->ancestorIds());
        $this->assertSame(
            [$refurbished->id, $phones->id, $smartphones->id],
            $smartphones->refresh()->ancestorIds(),
            'A descendant must not describe a parent it no longer has.',
        );
        $this->assertSame(2, $smartphones->depth);
    }

    #[Test]
    public function a_category_cannot_be_its_own_parent(): void
    {
        $category = $this->category('Electronics');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot be its own parent');

        app(SaveCategory::class)($category, ['parent_id' => $category->id], 'admin', 1);
    }

    #[Test]
    public function the_database_refuses_a_self_parent_even_without_the_domain_check(): void
    {
        $category = $this->category('Electronics');

        // Belt and braces: a hand-written UPDATE gets the same answer.
        $this->expectException(QueryException::class);

        app('db')->table('categories')->where('id', $category->id)->update(['parent_id' => $category->id]);
    }

    #[Test]
    public function a_deeper_cycle_is_prevented(): void
    {
        $electronics = $this->category('Electronics');
        $phones = $this->category('Mobile Phones', $electronics);
        $smartphones = $this->category('Smartphones', $phones);

        // Electronics under its own grandchild.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('its own ancestor');

        app(SaveCategory::class)($electronics, ['parent_id' => $smartphones->id], 'admin', 1);
    }

    #[Test]
    public function a_category_inherits_its_ancestors_attributes(): void
    {
        $clothing = $this->category('Clothing');
        $shirts = $this->category('Shirts', $clothing);

        $colour = Attribute::factory()->variantDefining()->create(['code' => 'colour', 'name' => 'Colour']);
        $sleeve = Attribute::factory()->create(['code' => 'sleeve', 'name' => 'Sleeve length']);

        $clothing->attributes()->attach($colour->id, ['is_required' => true]);
        $shirts->attributes()->attach($sleeve->id, ['is_required' => false]);

        $codes = $shirts->effectiveAttributes()->pluck('code')->all();

        // Colour is declared once, on Clothing, and every garment beneath
        // it gets it without re-declaration.
        $this->assertContains('colour', $codes);
        $this->assertContains('sleeve', $codes);

        // And inheritance runs downward only.
        $this->assertSame(['colour'], $clothing->effectiveAttributes()->pluck('code')->all());
    }

    #[Test]
    public function a_required_attribute_must_be_supplied(): void
    {
        ['category' => $category, 'attribute' => $storage] = $this->phoneSchema();
        $product = Product::factory()->create(['category_id' => $category->id]);

        try {
            app(SaveAttributeValues::class)($product, []);
            $this->fail('A product missing a required specification should not save.');
        } catch (AttributeValidationFailed $failed) {
            $this->assertArrayHasKey($storage->code, $failed->errors);
            $this->assertStringContainsString('is required', $failed->errors[$storage->code]);
        }
    }

    #[Test]
    public function an_attribute_the_category_does_not_use_is_refused(): void
    {
        ['category' => $category, 'attribute' => $storage] = $this->phoneSchema();
        $product = Product::factory()->create(['category_id' => $category->id]);

        try {
            app(SaveAttributeValues::class)($product, [$storage->code => 256, 'thread_count' => 400]);
            $this->fail('An attribute outside the category schema should not be stored.');
        } catch (AttributeValidationFailed $failed) {
            $this->assertArrayHasKey('thread_count', $failed->errors);
        }

        $this->assertDatabaseCount('product_attribute_values', 0);
    }

    #[Test]
    public function a_value_lands_in_the_column_its_type_belongs_in(): void
    {
        ['category' => $category, 'attribute' => $storage] = $this->phoneSchema();
        $product = Product::factory()->create(['category_id' => $category->id]);

        app(SaveAttributeValues::class)($product, [$storage->code => 256]);

        // Typed, not stringly: "storage >= 128" has to be a numeric
        // comparison for a filter to work at all.
        $this->assertDatabaseHas('product_attribute_values', [
            'product_id' => $product->id,
            'attribute_id' => $storage->id,
            'value_int' => 256,
            'value_text' => null,
        ]);
    }

    #[Test]
    public function a_select_value_must_be_one_of_the_declared_options(): void
    {
        $category = $this->category('Phones');
        $colour = Attribute::factory()->variantDefining()->create(['code' => 'colour', 'name' => 'Colour']);
        AttributeOption::factory()->create(['attribute_id' => $colour->id, 'value' => 'black', 'label' => 'Black']);
        $category->attributes()->attach($colour->id, ['is_required' => false]);

        $product = Product::factory()->create(['category_id' => $category->id]);

        app(SaveAttributeValues::class)($product, ['colour' => 'black']);
        $this->assertDatabaseCount('product_attribute_values', 1);

        // A value not on the list does not quietly become a new option.
        $this->expectException(AttributeValidationFailed::class);
        app(SaveAttributeValues::class)($product, ['colour' => 'chartreuse']);
    }

    #[Test]
    public function a_non_numeric_value_for_a_number_is_refused(): void
    {
        ['category' => $category, 'attribute' => $storage] = $this->phoneSchema();
        $product = Product::factory()->create(['category_id' => $category->id]);

        $this->expectException(AttributeValidationFailed::class);
        $this->expectExceptionMessage('whole number');

        app(SaveAttributeValues::class)($product, [$storage->code => 'roomy']);
    }

    #[Test]
    public function a_product_holds_one_value_per_attribute(): void
    {
        ['category' => $category, 'attribute' => $storage] = $this->phoneSchema();
        $product = Product::factory()->create(['category_id' => $category->id]);

        app(SaveAttributeValues::class)($product, [$storage->code => 128]);
        app(SaveAttributeValues::class)($product, [$storage->code => 256]);

        $this->assertDatabaseCount('product_attribute_values', 1);
        $this->assertDatabaseHas('product_attribute_values', ['value_int' => 256]);
    }

    #[Test]
    public function the_database_refuses_a_second_value_for_the_same_attribute(): void
    {
        ['category' => $category, 'attribute' => $storage] = $this->phoneSchema();
        $product = Product::factory()->create(['category_id' => $category->id]);

        app(SaveAttributeValues::class)($product, [$storage->code => 128]);

        // The unique index is the real guarantee; the action is the
        // friendly path to it.
        $this->expectException(QueryException::class);

        app('db')->table('product_attribute_values')->insert([
            'product_id' => $product->id,
            'attribute_id' => $storage->id,
            'product_variant_id' => null,
            'value_int' => 256,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function a_value_row_must_carry_exactly_one_value(): void
    {
        ['category' => $category, 'attribute' => $storage] = $this->phoneSchema();
        $product = Product::factory()->create(['category_id' => $category->id]);

        // Two values is as meaningless as none, and the database says so.
        $this->expectException(QueryException::class);

        app('db')->table('product_attribute_values')->insert([
            'product_id' => $product->id,
            'attribute_id' => $storage->id,
            'value_int' => 256,
            'value_text' => 'also this',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function clearing_a_value_removes_it_rather_than_storing_a_blank(): void
    {
        $category = $this->category('Phones');
        $notes = Attribute::factory()->create(['code' => 'notes', 'name' => 'Notes']);
        $category->attributes()->attach($notes->id, ['is_required' => false]);

        $product = Product::factory()->create(['category_id' => $category->id]);

        app(SaveAttributeValues::class)($product, ['notes' => 'Ships with a case']);
        $this->assertDatabaseCount('product_attribute_values', 1);

        app(SaveAttributeValues::class)($product, ['notes' => '']);
        $this->assertDatabaseCount('product_attribute_values', 0);
    }

    #[Test]
    public function only_a_single_valued_comparable_type_can_define_a_variant(): void
    {
        // A variant is a coordinate — "Black / 256GB" — so a paragraph or
        // a set cannot be one of its axes.
        $this->assertTrue(AttributeType::Select->canDefineVariants());
        $this->assertTrue(AttributeType::Integer->canDefineVariants());
        $this->assertFalse(AttributeType::MultiSelect->canDefineVariants());
        $this->assertFalse(AttributeType::Boolean->canDefineVariants());
        $this->assertFalse(AttributeType::Decimal->canDefineVariants());
    }

    #[Test]
    public function a_product_cannot_be_published_into_a_category_customers_cannot_browse(): void
    {
        $hidden = Category::factory()->create(['name' => 'Retired lines', 'is_visible' => false]);
        $product = Product::factory()
            ->status(ProductStatus::Approved)
            ->create(['category_id' => $hidden->id]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot browse');

        app(TransitionProduct::class)($product, ProductStatus::Published, 'admin', $this->admin()->id);
    }

    #[Test]
    public function a_hidden_category_can_still_hold_an_approved_product(): void
    {
        // Accepting a product into the catalogue and putting it on the
        // storefront are different decisions, and only the second one
        // needs somewhere for a customer to arrive from.
        $hidden = Category::factory()->create(['is_visible' => false]);
        $product = Product::factory()
            ->status(ProductStatus::PendingReview)
            ->create(['category_id' => $hidden->id]);

        $approved = app(TransitionProduct::class)($product, ProductStatus::Approved, 'admin', $this->admin()->id);

        $this->assertSame(ProductStatus::Approved, $approved->status);
    }

    #[Test]
    public function a_hidden_category_has_no_public_page(): void
    {
        $hidden = Category::factory()->create(['name' => 'Retired lines', 'is_visible' => false]);
        $visible = Category::factory()->create(['name' => 'Kettles', 'is_visible' => true]);

        $this->get('/categories/'.$hidden->slug)->assertNotFound();
        $this->get('/categories/'.$visible->slug)->assertOk();
    }

    private function admin(): AdminUser
    {
        return $this->makeAdmin(AdminRole::CatalogModerator);
    }

    /** @return array{category: Category, attribute: Attribute} */
    private function phoneSchema(): array
    {
        $category = $this->category('Phones');
        $storage = Attribute::factory()
            ->ofType(AttributeType::Integer)
            ->create(['code' => 'storage', 'name' => 'Storage', 'unit' => 'GB']);

        $category->attributes()->attach($storage->id, ['is_required' => true]);

        return ['category' => $category->refresh(), 'attribute' => $storage];
    }

    private function category(string $name, ?Category $parent = null): Category
    {
        return app(SaveCategory::class)(null, [
            'name' => $name,
            'slug' => str($name)->slug()->value().'-'.fake()->unique()->numberBetween(1, 99999),
            'parent_id' => $parent?->id,
        ], 'admin', 1);
    }
}
