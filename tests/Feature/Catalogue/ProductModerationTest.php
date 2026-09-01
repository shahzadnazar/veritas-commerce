<?php

declare(strict_types=1);

namespace Tests\Feature\Catalogue;

use App\Modules\Catalog\Actions\ApproveProduct;
use App\Modules\Catalog\Actions\DecideProduct;
use App\Modules\Catalog\Actions\ProposeProduct;
use App\Modules\Catalog\Actions\TransitionProduct;
use App\Modules\Catalog\Enums\AttributeType;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Events\ProductApproved;
use App\Modules\Catalog\Events\ProductProposed;
use App\Modules\Catalog\Exceptions\AttributeValidationFailed;
use App\Modules\Catalog\Exceptions\DuplicateProduct;
use App\Modules\Catalog\Models\Attribute;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductProposalEvent;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Sellers\Models\SellerAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Proposing a product, and what a moderator does with it.
 */
final class ProductModerationTest extends TestCase
{
    use RefreshDatabase;

    private int $moderatorId;

    protected function setUp(): void
    {
        parent::setUp();

        // A real row: reviewed_by_admin_id is a foreign key, and a test
        // that invents an id is not exercising the same write production
        // does.
        $this->moderatorId = $this->makeAdmin(AdminRole::CatalogModerator)->id;
    }

    #[Test]
    public function a_seller_can_propose_a_product_the_catalogue_does_not_have(): void
    {
        Event::fake([ProductProposed::class]);

        ['seller' => $seller, 'category' => $category] = $this->kettleCategory();

        $product = app(ProposeProduct::class)(
            attributes: ['title' => 'Aeris Cordless Kettle 1.2L', 'category_id' => $category->id],
            specifications: ['capacity' => 1200],
            sellerAccountId: $seller->id,
        );

        $this->assertSame(ProductStatus::PendingReview, $product->status);
        $this->assertSame($seller->id, $product->created_by_seller_account_id);
        $this->assertSame('aeris-cordless-kettle-1-2l', $product->slug);
        $this->assertNotNull($product->submitted_at);

        Event::assertDispatched(ProductProposed::class);
        $this->assertDatabaseHas('audit_logs', ['action' => 'catalogue.product.proposed']);
    }

    #[Test]
    public function a_proposal_cannot_decide_its_own_moderation_state(): void
    {
        ['seller' => $seller, 'category' => $category] = $this->kettleCategory();

        // A payload claiming to be published, or proposed by somebody else,
        // is describing a product — not deciding anything.
        $product = app(ProposeProduct::class)(
            attributes: [
                'title' => 'Sneaky Kettle',
                'category_id' => $category->id,
                'status' => ProductStatus::Published->value,
                'created_by_seller_account_id' => 99999,
                'published_at' => now(),
            ],
            specifications: ['capacity' => 900],
            sellerAccountId: $seller->id,
        );

        $this->assertSame(ProductStatus::PendingReview, $product->status);
        $this->assertSame($seller->id, $product->created_by_seller_account_id);
        $this->assertNull($product->published_at);
    }

    #[Test]
    public function a_proposal_missing_a_required_specification_is_refused(): void
    {
        ['seller' => $seller, 'category' => $category] = $this->kettleCategory();

        $this->expectException(AttributeValidationFailed::class);

        app(ProposeProduct::class)(
            attributes: ['title' => 'Nameless Kettle', 'category_id' => $category->id],
            specifications: [],
            sellerAccountId: $seller->id,
        );
    }

    #[Test]
    public function a_proposal_for_a_barcode_the_catalogue_holds_is_refused_with_the_alternative(): void
    {
        ['seller' => $seller, 'category' => $category] = $this->kettleCategory();
        $existing = Product::factory()->create(['category_id' => $category->id, 'ean' => '9780306406157']);

        try {
            app(ProposeProduct::class)(
                attributes: [
                    'title' => 'A Different Name Entirely',
                    'category_id' => $category->id,
                    'ean' => '978-0-306-40615-7',
                ],
                specifications: ['capacity' => 1000],
                sellerAccountId: $seller->id,
            );
            $this->fail('A duplicate barcode should not create a second catalogue entry.');
        } catch (DuplicateProduct $duplicate) {
            // "This exists" with no way to reach it is the fastest route to
            // a duplicate under a slightly different name.
            $this->assertSame($existing->id, $duplicate->match->product->id);
            $this->assertTrue($duplicate->match->isConclusive);
        }

        $this->assertSame(1, Product::query()->count());
    }

    #[Test]
    public function a_moderator_can_approve_and_the_approval_is_idempotent(): void
    {
        Event::fake([ProductApproved::class]);

        $product = $this->proposed();

        $first = app(ApproveProduct::class)($product, $this->moderatorId);
        $second = app(ApproveProduct::class)($product->refresh(), $this->moderatorId);
        $third = app(ApproveProduct::class)($product->refresh(), $this->moderatorId);

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->id, $third->id);
        $this->assertSame(ProductStatus::Approved, $product->refresh()->status);

        // One approval, however many times the button is pressed: one
        // history row, one event, one audit record.
        $this->assertSame(
            1,
            ProductProposalEvent::query()
                ->where('product_id', $product->id)
                ->where('to_status', ProductStatus::Approved->value)
                ->count(),
        );
        Event::assertDispatchedTimes(ProductApproved::class, 1);
        $this->assertSame(1, app('db')->table('audit_logs')->where('action', 'catalogue.product.approved')->count());
    }

    #[Test]
    public function approving_never_produces_a_second_catalogue_entry(): void
    {
        $product = $this->proposed();

        app(ApproveProduct::class)($product, $this->moderatorId);
        app(ApproveProduct::class)($product->refresh(), $this->makeAdmin(AdminRole::CatalogModerator)->id);

        $this->assertSame(1, Product::query()->count());
    }

    #[Test]
    public function approving_and_publishing_are_separate_decisions(): void
    {
        $product = $this->proposed();

        app(ApproveProduct::class)($product, $this->moderatorId);
        $this->assertSame(ProductStatus::Approved, $product->refresh()->status);
        $this->assertFalse($product->status->isPublic(), 'In the catalogue is not the same as on the storefront.');

        app(ApproveProduct::class)($product->refresh(), $this->moderatorId, publish: true);
        $this->assertSame(ProductStatus::Published, $product->refresh()->status);
        $this->assertNotNull($product->published_at);
    }

    #[Test]
    public function a_rejection_requires_a_reason(): void
    {
        $product = $this->proposed();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requires a written reason');

        app(DecideProduct::class)->reject($product, $this->moderatorId, '   ');
    }

    #[Test]
    public function a_rejection_records_the_reason_and_keeps_the_proposal(): void
    {
        $product = $this->proposed();
        $reason = 'The photographs show a different model to the one described.';

        app(DecideProduct::class)->reject($product, $this->moderatorId, $reason);

        $this->assertSame(ProductStatus::Rejected, $product->refresh()->status);
        $this->assertSame($reason, $product->moderation_reason);
        // Kept, not deleted: the proposal is part of the record.
        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'catalogue.product.rejected', 'reason' => $reason]);
    }

    #[Test]
    public function changes_requested_is_not_a_rejection(): void
    {
        $product = $this->proposed();

        app(DecideProduct::class)->requestChanges($product, $this->moderatorId, 'Please add the capacity in litres to the title.');

        $fresh = $product->refresh();

        $this->assertSame(ProductStatus::ChangesRequested, $fresh->status);
        $this->assertTrue($fresh->status->isEditableByProposer(), 'The seller can fix it and resubmit.');
        $this->assertDatabaseMissing('audit_logs', ['action' => 'catalogue.product.rejected']);

        // And the loop closes: corrected, it goes back into the queue.
        app(TransitionProduct::class)($fresh, ProductStatus::PendingReview, 'seller', 1);
        $this->assertSame(ProductStatus::PendingReview, $fresh->refresh()->status);
    }

    #[Test]
    public function a_decision_clears_the_previous_reason(): void
    {
        $product = $this->proposed();

        app(DecideProduct::class)->requestChanges($product, $this->moderatorId, 'The title is missing the capacity.');
        app(TransitionProduct::class)($product->refresh(), ProductStatus::PendingReview, 'seller', 1);
        app(ApproveProduct::class)($product->refresh(), $this->moderatorId);

        // A stale correction note must not reappear on an approved product.
        $this->assertNull($product->refresh()->moderation_reason);
    }

    #[Test]
    public function an_illegal_transition_is_refused(): void
    {
        $product = $this->proposed();
        app(DecideProduct::class)->reject($product, $this->moderatorId, 'Not something this marketplace sells.');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot move from rejected to published');

        app(TransitionProduct::class)($product->refresh(), ProductStatus::Published, 'admin', $this->moderatorId);
    }

    #[Test]
    public function the_history_records_every_step_and_cannot_be_rewritten(): void
    {
        $product = $this->proposed();
        app(DecideProduct::class)->requestChanges($product, $this->moderatorId, 'The capacity is missing.');
        app(TransitionProduct::class)($product->refresh(), ProductStatus::PendingReview, 'seller', 1);
        app(ApproveProduct::class)($product->refresh(), $this->moderatorId, publish: true);

        $history = ProductProposalEvent::query()->where('product_id', $product->id)->orderBy('id')->pluck('to_status')->all();

        $this->assertSame(
            ['pending_review', 'changes_requested', 'pending_review', 'approved', 'published'],
            $history,
        );

        $this->expectException(RuntimeException::class);
        ProductProposalEvent::query()->where('product_id', $product->id)->first()?->update(['reason' => 'rewritten']);
    }

    #[Test]
    public function a_suspended_product_stops_accepting_offers(): void
    {
        $product = $this->proposed();
        app(ApproveProduct::class)($product, $this->moderatorId, publish: true);

        app(DecideProduct::class)->suspend($product->refresh(), $this->moderatorId, 'A safety recall was issued for this model.');

        $fresh = $product->refresh();
        $this->assertSame(ProductStatus::Suspended, $fresh->status);
        $this->assertFalse($fresh->status->acceptsOffers());
        $this->assertFalse($fresh->isPubliclyVisible());
        $this->assertDatabaseHas('audit_logs', ['action' => 'catalogue.product.suspended']);
    }

    /** @return array{seller: SellerAccount, category: Category} */
    private function kettleCategory(): array
    {
        ['seller' => $seller] = $this->makeSeller();
        $category = Category::factory()->create(['name' => 'Kettles']);

        $capacity = Attribute::factory()
            ->ofType(AttributeType::Integer)
            ->create(['code' => 'capacity', 'name' => 'Capacity', 'unit' => 'ml']);

        $category->attributes()->attach($capacity->id, ['is_required' => true]);

        return ['seller' => $seller, 'category' => $category->refresh()];
    }

    private function proposed(): Product
    {
        ['seller' => $seller, 'category' => $category] = $this->kettleCategory();

        return app(ProposeProduct::class)(
            attributes: ['title' => 'Aeris Cordless Kettle', 'category_id' => $category->id],
            specifications: ['capacity' => 1200],
            sellerAccountId: $seller->id,
        );
    }
}
