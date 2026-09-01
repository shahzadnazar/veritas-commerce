<?php

declare(strict_types=1);

namespace Tests\Feature\Catalogue;

use App\Modules\Catalog\Enums\AttributeType;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Attribute;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Identity\Enums\AdminPermission;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Sellers\Models\SellerAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Who may decide what the marketplace sells.
 *
 * Catalogue authority is eight capabilities rather than one flag, so these
 * cases are mostly about a role holding some of them and being refused the
 * rest — which is the only interesting question once everybody can log in.
 */
final class AdminCatalogueAccessTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_queue_opens_on_what_is_waiting_rather_than_on_everything(): void
    {
        Product::factory()->status(ProductStatus::PendingReview)->create(['title' => 'Waiting Kettle']);
        Product::factory()->create(['title' => 'Long Since Published']);

        $this->asAdmin($this->makeAdmin(AdminRole::CatalogModerator))
            ->get('/admin/catalogue/products')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Catalogue/Products')
                ->has('products.data', 1)
                ->where('products.data.0.title', 'Waiting Kettle'));
    }

    #[Test]
    public function a_moderator_sees_the_whole_proposal_on_one_page(): void
    {
        $seller = SellerAccount::factory()->create(['legal_name' => 'Aeris Trading Ltd']);
        $product = Product::factory()
            ->proposedBy($seller->id)
            ->create(['title' => 'Aeris Kettle', 'gtin' => '00012345678905']);

        $this->asAdmin($this->makeAdmin(AdminRole::CatalogModerator))
            ->get("/admin/catalogue/products/{$product->public_id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Catalogue/ProductReview')
                ->where('product.title', 'Aeris Kettle')
                ->where('product.identifiers.gtin', '00012345678905')
                ->where('product.proposedBy', 'Aeris Trading Ltd')
                ->has('history'));
    }

    #[Test]
    public function an_admin_with_the_permission_approves_and_publishes(): void
    {
        $product = Product::factory()->status(ProductStatus::PendingReview)->create();

        $this->asAdmin($this->makeAdmin(AdminRole::CatalogModerator))
            ->post("/admin/catalogue/products/{$product->public_id}/approve", ['publish' => true])
            ->assertRedirect();

        $product->refresh();

        $this->assertSame(ProductStatus::Published, $product->status);
        $this->assertNotNull($product->published_at);
        $this->assertNotNull($product->reviewed_by_admin_id);
    }

    #[Test]
    public function accepting_a_product_and_putting_it_on_the_storefront_are_separate_decisions(): void
    {
        $product = Product::factory()->status(ProductStatus::PendingReview)->create();
        $moderator = $this->makeAdmin(AdminRole::CatalogModerator);

        $this->asAdmin($moderator)
            ->post("/admin/catalogue/products/{$product->public_id}/approve")
            ->assertRedirect();

        $this->assertSame(ProductStatus::Approved, $product->refresh()->status);
        $this->assertNull($product->published_at);

        $this->asAdmin($moderator)
            ->post("/admin/catalogue/products/{$product->public_id}/approve", ['publish' => true])
            ->assertRedirect();

        $this->assertSame(ProductStatus::Published, $product->refresh()->status);
    }

    #[Test]
    public function a_platform_role_without_catalogue_permissions_cannot_approve_a_product(): void
    {
        $product = Product::factory()->status(ProductStatus::PendingReview)->create();

        // Seller operations governs sellers. Approving the goods is a
        // different job, and holding both is how one compromised account
        // becomes a catalogue incident.
        $this->assertFalse(AdminRole::SellerOperations->can(AdminPermission::CatalogueProductApprove));

        $this->asAdmin($this->makeAdmin(AdminRole::SellerOperations))
            ->post("/admin/catalogue/products/{$product->public_id}/approve", ['publish' => true])
            ->assertForbidden();

        $this->assertSame(ProductStatus::PendingReview, $product->refresh()->status);
    }

    #[Test]
    public function a_read_only_role_can_open_the_catalogue_and_decide_nothing_in_it(): void
    {
        $product = Product::factory()->status(ProductStatus::PendingReview)->create();
        $analyst = $this->makeAdmin(AdminRole::Analyst);

        $this->asAdmin($analyst)->get('/admin/catalogue/products')->assertOk();

        $this->asAdmin($analyst)
            ->post("/admin/catalogue/products/{$product->public_id}/reject", ['reason' => 'Because I can.'])
            ->assertForbidden();

        $this->asAdmin($analyst)
            ->post("/admin/catalogue/products/{$product->public_id}/suspend", ['reason' => 'Because I can.'])
            ->assertForbidden();

        $this->assertSame(ProductStatus::PendingReview, $product->refresh()->status);
    }

    #[Test]
    public function a_rejection_without_a_reason_is_refused_by_the_server(): void
    {
        $product = Product::factory()->status(ProductStatus::PendingReview)->create();

        $this->asAdmin($this->makeAdmin(AdminRole::CatalogModerator))
            ->post("/admin/catalogue/products/{$product->public_id}/reject", ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertSame(ProductStatus::PendingReview, $product->refresh()->status);
    }

    #[Test]
    public function asking_for_changes_leaves_the_proposal_open(): void
    {
        $product = Product::factory()->status(ProductStatus::PendingReview)->create();

        $this->asAdmin($this->makeAdmin(AdminRole::CatalogModerator))
            ->post("/admin/catalogue/products/{$product->public_id}/request-changes", [
                'reason' => 'The capacity is missing.',
            ])
            ->assertRedirect();

        $product->refresh();

        $this->assertSame(ProductStatus::ChangesRequested, $product->status);
        $this->assertTrue($product->status->isEditableByProposer());
        $this->assertSame('The capacity is missing.', $product->moderation_reason);
    }

    #[Test]
    public function the_catalogue_team_can_edit_a_product_a_seller_no_longer_owns(): void
    {
        $seller = SellerAccount::factory()->create();
        $category = Category::factory()->create(['name' => 'Kettles']);
        $product = Product::factory()->create([
            'title' => 'Aeris Ketle',
            'created_by_seller_account_id' => $seller->id,
        ]);

        $this->asAdmin($this->makeAdmin(AdminRole::CatalogModerator))
            ->patch("/admin/catalogue/products/{$product->public_id}", [
                'title' => 'Aeris Kettle',
                'category_id' => $category->id,
            ])
            ->assertRedirect();

        $this->assertSame('Aeris Kettle', $product->refresh()->title);
    }

    #[Test]
    public function a_marketplace_admin_reviews_products_but_does_not_reshape_the_taxonomy(): void
    {
        $admin = $this->makeAdmin(AdminRole::MarketplaceAdmin);

        $this->assertTrue(AdminRole::MarketplaceAdmin->can(AdminPermission::CatalogueProductApprove));
        $this->assertFalse(AdminRole::MarketplaceAdmin->can(AdminPermission::CatalogueCategoryManage));

        $this->asAdmin($admin)->get('/admin/catalogue/taxonomy')->assertOk();

        $this->asAdmin($admin)
            ->post('/admin/catalogue/categories', ['name' => 'Kettles', 'slug' => 'kettles'])
            ->assertForbidden();

        $this->assertDatabaseMissing('categories', ['slug' => 'kettles']);
    }

    #[Test]
    public function a_moderator_creates_a_category_and_assigns_it_an_attribute(): void
    {
        $moderator = $this->makeAdmin(AdminRole::CatalogModerator);

        $this->asAdmin($moderator)
            ->post('/admin/catalogue/categories', ['name' => 'Kettles', 'slug' => 'kettles'])
            ->assertRedirect();

        $category = Category::query()->where('slug', 'kettles')->firstOrFail();

        $this->asAdmin($moderator)
            ->post('/admin/catalogue/attributes', [
                'code' => 'capacity',
                'name' => 'Capacity',
                'data_type' => AttributeType::Integer->value,
                'unit' => 'ml',
                'is_filterable' => true,
            ])
            ->assertRedirect();

        $attribute = Attribute::query()->where('code', 'capacity')->firstOrFail();

        $this->asAdmin($moderator)
            ->post("/admin/catalogue/categories/{$category->public_id}/attributes", [
                'attribute_id' => $attribute->id,
                'is_required' => true,
            ])
            ->assertRedirect();

        $this->assertTrue($category->refresh()->attributes()->where('attributes.id', $attribute->id)->exists());
    }

    #[Test]
    public function a_cycle_is_refused_as_a_validation_message_not_a_server_error(): void
    {
        $moderator = $this->makeAdmin(AdminRole::CatalogModerator);
        $parent = Category::factory()->create(['name' => 'Kitchen']);
        $child = Category::factory()->create(['name' => 'Kettles', 'parent_id' => $parent->id]);

        $this->asAdmin($moderator)
            ->patch("/admin/catalogue/categories/{$parent->public_id}", [
                'name' => 'Kitchen',
                'slug' => $parent->slug,
                'parent_id' => $child->id,
            ])
            ->assertSessionHasErrors('parent_id');

        $this->assertNull($parent->refresh()->parent_id);
    }

    #[Test]
    public function only_a_brand_permission_turns_a_sellers_suggestion_into_a_marketplace_brand(): void
    {
        $seller = SellerAccount::factory()->create();
        $brand = Brand::factory()->create([
            'name' => 'Aeris',
            'proposed_by_seller_account_id' => $seller->id,
            'approved_at' => null,
        ]);

        $this->asAdmin($this->makeAdmin(AdminRole::MarketplaceAdmin))
            ->post("/admin/catalogue/brands/{$brand->public_id}/approve")
            ->assertForbidden();

        $this->assertNull($brand->refresh()->approved_at);

        $this->asAdmin($this->makeAdmin(AdminRole::CatalogModerator))
            ->post("/admin/catalogue/brands/{$brand->public_id}/approve")
            ->assertRedirect();

        $this->assertNotNull($brand->refresh()->approved_at);
    }

    #[Test]
    public function a_seller_session_cannot_reach_the_moderation_queue(): void
    {
        ['user' => $user] = $this->makeSeller();

        $this->asUser($user)->get('/admin/catalogue/products')->assertRedirect('/admin/login');
    }
}
