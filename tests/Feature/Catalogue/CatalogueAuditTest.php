<?php

declare(strict_types=1);

namespace Tests\Feature\Catalogue;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Actions\ResolveBrand;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Offers\Enums\OfferStatus;
use App\Modules\Offers\Models\Offer;
use App\Modules\Sellers\Models\SellerAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What the record says afterwards.
 *
 * A decision nobody can reconstruct six months later is not governance, so
 * every catalogue decision writes who acted, on what, why, and what the
 * values were before and after. These assertions go through the real HTTP
 * routes, because an audit entry written only by a directly-called action
 * proves nothing about the path a person actually takes.
 */
final class CatalogueAuditTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function approving_a_product_is_audited_with_the_moderator_who_did_it(): void
    {
        $seller = SellerAccount::factory()->create();
        $product = Product::factory()->proposedBy($seller->id)->create(['title' => 'Aeris Kettle']);
        $moderator = $this->makeAdmin(AdminRole::CatalogModerator);

        $this->asAdmin($moderator)
            ->post("/admin/catalogue/products/{$product->public_id}/approve", ['publish' => true])
            ->assertRedirect();

        $entry = $this->entry('catalogue.product.approved');

        $this->assertSame('admin', $entry->actor_type);
        $this->assertSame($moderator->id, $entry->actor_id);
        $this->assertSame(Product::class, $entry->subject_type);
        $this->assertSame($product->id, $entry->subject_id);
        $this->assertSame('Aeris Kettle', $entry->changes['title'] ?? null);
        $this->assertSame($seller->id, $entry->changes['proposed_by'] ?? null);
    }

    #[Test]
    public function a_rejection_records_the_reason_the_seller_was_given(): void
    {
        $product = Product::factory()->status(ProductStatus::PendingReview)->create();
        $moderator = $this->makeAdmin(AdminRole::CatalogModerator);

        $this->asAdmin($moderator)
            ->post("/admin/catalogue/products/{$product->public_id}/reject", [
                'reason' => 'This is a listing for a service, not a product.',
            ])
            ->assertRedirect();

        $entry = $this->entry('catalogue.product.rejected');

        $this->assertSame('This is a listing for a service, not a product.', $entry->reason);
        $this->assertSame($moderator->id, $entry->actor_id);
        $this->assertSame(ProductStatus::PendingReview->value, $entry->changes['status']['from'] ?? null);
        $this->assertSame(ProductStatus::Rejected->value, $entry->changes['status']['to'] ?? null);
    }

    #[Test]
    public function asking_for_changes_and_suspending_are_recorded_as_different_things(): void
    {
        $moderator = $this->makeAdmin(AdminRole::CatalogModerator);
        $proposal = Product::factory()->status(ProductStatus::PendingReview)->create();
        $published = Product::factory()->create();

        $this->asAdmin($moderator)
            ->post("/admin/catalogue/products/{$proposal->public_id}/request-changes", ['reason' => 'Add the capacity.'])
            ->assertRedirect();

        $this->asAdmin($moderator)
            ->post("/admin/catalogue/products/{$published->public_id}/suspend", ['reason' => 'Counterfeit reported.'])
            ->assertRedirect();

        // The distinction matters in the reporting afterwards: "we
        // rejected 400 products" and "we asked 400 sellers for a
        // correction" describe very different marketplaces.
        $this->assertSame('Add the capacity.', $this->entry('catalogue.product.changes_requested')->reason);
        $this->assertSame('Counterfeit reported.', $this->entry('catalogue.product.suspended')->reason);
    }

    #[Test]
    public function editing_the_canonical_record_keeps_the_values_it_replaced(): void
    {
        $category = Category::factory()->create(['name' => 'Kettles']);
        $product = Product::factory()->create(['title' => 'Aeris Ketle', 'gtin' => null]);
        $moderator = $this->makeAdmin(AdminRole::CatalogModerator);

        $this->asAdmin($moderator)
            ->patch("/admin/catalogue/products/{$product->public_id}", [
                'title' => 'Aeris Kettle',
                'category_id' => $category->id,
                'gtin' => '00012345678905',
            ])
            ->assertRedirect();

        $changes = $this->entry('catalogue.product.edited')->changes;

        $this->assertIsArray($changes);
        $this->assertArrayHasKey('from', $changes);
        $this->assertArrayHasKey('to', $changes);

        /** @var array<string, mixed> $from */
        $from = $changes['from'];
        /** @var array<string, mixed> $to */
        $to = $changes['to'];

        $this->assertSame('Aeris Ketle', $from['title'] ?? null);
        $this->assertSame('Aeris Kettle', $to['title'] ?? null);
        // Present and null, not absent: the record says the barcode was
        // empty before, which is different from never mentioning it.
        $this->assertArrayHasKey('gtin', $from);
        $this->assertNull($from['gtin']);
        $this->assertSame('00012345678905', $to['gtin'] ?? null);
        $this->assertSame($moderator->id, $this->entry('catalogue.product.edited')->actor_id);
    }

    #[Test]
    public function renaming_a_product_retires_its_address_rather_than_dropping_it(): void
    {
        $category = Category::factory()->create();
        $product = Product::factory()->create(['title' => 'Aeris Ketle']);
        $oldSlug = $product->slug;

        $this->asAdmin($this->makeAdmin(AdminRole::CatalogModerator))
            ->patch("/admin/catalogue/products/{$product->public_id}", [
                'title' => 'Aeris Kettle',
                'category_id' => $category->id,
            ])
            ->assertRedirect();

        $this->assertNotSame($oldSlug, $product->refresh()->slug);
        $this->assertDatabaseHas('product_slug_history', [
            'product_id' => $product->id,
            'old_slug' => $oldSlug,
        ]);
    }

    #[Test]
    public function an_edit_that_changes_nothing_writes_nothing(): void
    {
        $product = Product::factory()->create(['title' => 'Aeris Kettle']);

        $this->asAdmin($this->makeAdmin(AdminRole::CatalogModerator))
            ->patch("/admin/catalogue/products/{$product->public_id}", [
                'title' => 'Aeris Kettle',
                'category_id' => $product->category_id,
            ])
            ->assertRedirect();

        // A log full of no-ops is a log nobody reads.
        $this->assertDatabaseMissing('audit_logs', ['action' => 'catalogue.product.edited']);
    }

    #[Test]
    public function suspending_a_listing_is_audited_against_the_offer(): void
    {
        ['seller' => $seller, 'user' => $user] = $this->makeSeller();
        $offer = Offer::factory()->forSeller($seller)->create();

        $this->asUser($user)
            ->post("/seller/offers/{$offer->public_id}/status", [
                'status' => OfferStatus::Suspended->value,
                'reason' => 'Out of stock for the rest of the season.',
            ])
            ->assertRedirect();

        $entry = $this->entry('catalogue.offer.'.OfferStatus::Suspended->value);

        $this->assertSame('seller', $entry->actor_type);
        $this->assertSame(Offer::class, $entry->subject_type);
        $this->assertSame($offer->id, $entry->subject_id);
        $this->assertSame('Out of stock for the rest of the season.', $entry->reason);
        $this->assertSame(OfferStatus::Suspended, $offer->refresh()->status);
    }

    #[Test]
    public function a_listing_going_live_is_recorded_too(): void
    {
        ['seller' => $seller, 'user' => $user] = $this->makeSeller();
        $offer = Offer::factory()->forSeller($seller)->draft()->create();

        $this->asUser($user)
            ->post("/seller/offers/{$offer->public_id}/status", ['status' => OfferStatus::Published->value])
            ->assertRedirect();

        $entry = $this->entry('catalogue.offer.'.OfferStatus::Published->value);

        $this->assertSame($offer->id, $entry->subject_id);
        $this->assertSame(OfferStatus::Published, $offer->refresh()->status);
    }

    #[Test]
    public function a_seller_proposing_a_brand_and_a_moderator_accepting_it_are_both_recorded(): void
    {
        $seller = SellerAccount::factory()->create();
        $brand = app(ResolveBrand::class)->propose('Aeris', $seller->id);

        $moderator = $this->makeAdmin(AdminRole::CatalogModerator);

        $this->asAdmin($moderator)
            ->post("/admin/catalogue/brands/{$brand->public_id}/approve")
            ->assertRedirect();

        $proposed = $this->entry('catalogue.brand.proposed');
        $approved = $this->entry('catalogue.brand.approved');

        $this->assertSame('seller', $proposed->actor_type);
        $this->assertSame($seller->id, $proposed->actor_id);
        $this->assertSame('admin', $approved->actor_type);
        $this->assertSame($moderator->id, $approved->actor_id);
        $this->assertSame(Brand::class, $approved->subject_type);
    }

    #[Test]
    public function creating_a_category_records_who_reshaped_the_taxonomy(): void
    {
        $moderator = $this->makeAdmin(AdminRole::CatalogModerator);

        $this->asAdmin($moderator)
            ->post('/admin/catalogue/categories', ['name' => 'Kettles', 'slug' => 'kettles'])
            ->assertRedirect();

        $entry = $this->entry('catalogue.category.created');

        $this->assertSame('admin', $entry->actor_type);
        $this->assertSame($moderator->id, $entry->actor_id);
    }

    #[Test]
    public function no_catalogue_audit_entry_carries_a_private_seller_document(): void
    {
        $seller = SellerAccount::factory()->create();
        $product = Product::factory()->proposedBy($seller->id)->create();

        $this->asAdmin($this->makeAdmin(AdminRole::CatalogModerator))
            ->post("/admin/catalogue/products/{$product->public_id}/approve", ['publish' => true])
            ->assertRedirect();

        foreach (AuditLog::query()->where('action', 'like', 'catalogue.%')->get() as $entry) {
            $encoded = json_encode($entry->changes) ?: '';

            // Product moderation is a public-facing record. Verification
            // paperwork belongs to seller governance and stays there.
            $this->assertStringNotContainsString('documents/', $encoded);
            $this->assertStringNotContainsString('tax_id', $encoded);
        }
    }

    private function entry(string $action): AuditLog
    {
        return AuditLog::query()->where('action', $action)->latest('id')->firstOrFail();
    }
}
