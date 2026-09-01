<?php

declare(strict_types=1);

namespace Tests\Feature\Catalogue;

use App\Modules\Catalog\Actions\ApproveProduct;
use App\Modules\Catalog\Actions\AttachProductImage;
use App\Modules\Catalog\Actions\DecideProduct;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Jobs\ProcessProductImage;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductMedia;
use App\Modules\Catalog\Notifications\ProductDecided;
use App\Modules\Catalog\Queries\BuildIndexableProduct;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Media\Contracts\ObjectStore;
use App\Modules\Media\Enums\Visibility;
use App\Modules\Offers\Actions\TransitionOffer;
use App\Modules\Offers\Enums\OfferStatus;
use App\Modules\Offers\Models\Offer;
use App\Modules\Search\Contracts\SearchIndex;
use App\Modules\Search\Jobs\ReindexProduct;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * What happens after a catalogue decision: indexing, mail and media, all
 * on queues, none of them able to fail the decision itself.
 */
final class CatalogueSideEffectsTest extends TestCase
{
    use RefreshDatabase;

    private int $moderatorId;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media');
        Notification::fake();
        $this->moderatorId = $this->makeAdmin(AdminRole::CatalogModerator)->id;
    }

    #[Test]
    public function approving_a_product_queues_a_reindex_rather_than_indexing_inline(): void
    {
        Bus::fake([ReindexProduct::class]);

        $product = Product::factory()->status(ProductStatus::PendingReview)->create();

        app(ApproveProduct::class)($product, $this->moderatorId);

        // A slow search engine must never be able to fail an approval.
        Bus::assertDispatched(ReindexProduct::class, fn (ReindexProduct $job): bool => $job->productId === $product->id);
    }

    #[Test]
    public function an_offer_going_live_reindexes_the_product_it_lists(): void
    {
        Bus::fake([ReindexProduct::class]);

        ['seller' => $seller, 'store' => $store] = $this->makeSeller();
        $product = Product::factory()->status(ProductStatus::Published)->create();

        $offer = Offer::factory()->create([
            'seller_account_id' => $seller->id,
            'store_id' => $store->id,
            'product_id' => $product->id,
            'product_variant_id' => null,
            'status' => OfferStatus::Draft->value,
        ]);

        app(TransitionOffer::class)(
            $offer,
            OfferStatus::Published,
            'seller',
            1,
        );

        // The product's document is what carries price and availability.
        Bus::assertDispatched(ReindexProduct::class, fn (ReindexProduct $job): bool => $job->productId === $product->id);
    }

    #[Test]
    public function the_index_holds_what_a_customer_could_search_for(): void
    {
        ['seller' => $seller, 'store' => $store] = $this->makeSeller();

        $brand = Brand::factory()->create(['name' => 'Northline Audio']);
        $product = Product::factory()->status(ProductStatus::Published)->create([
            'title' => 'Studio Reference Headphones',
            'brand_id' => $brand->id,
            'ean' => '9780306406157',
        ]);

        Offer::factory()->create([
            'seller_account_id' => $seller->id,
            'store_id' => $store->id,
            'product_id' => $product->id,
            'product_variant_id' => null,
            'price_minor' => 14_999,
            'status' => OfferStatus::Published->value,
        ]);

        $document = app(BuildIndexableProduct::class)->describe($product->id);

        $this->assertNotNull($document);
        $this->assertSame('Studio Reference Headphones', $document->title);
        $this->assertSame('Northline Audio', $document->brandName);
        $this->assertTrue($document->isPublic);
        // The price comes from the same eligibility rule the storefront
        // uses, so search cannot advertise a price nobody can buy at.
        $this->assertSame(14_999, $document->lowestPriceMinor);
        $this->assertSame(1, $document->offerCount);
        $this->assertStringContainsString('9780306406157', $document->searchableText());
    }

    #[Test]
    public function indexing_the_same_product_twice_leaves_one_document(): void
    {
        $product = Product::factory()->status(ProductStatus::Published)->create();
        $index = app(SearchIndex::class);
        $build = app(BuildIndexableProduct::class);

        $document = $build->describe($product->id);
        $this->assertNotNull($document);

        // Jobs are retried; a redelivery must not duplicate a row.
        $index->index($document);
        $index->index($document);
        $index->index($document);

        $this->assertDatabaseCount('product_search_documents', 1);
    }

    #[Test]
    public function a_published_product_is_findable_and_an_unpublished_one_is_not(): void
    {
        $index = app(SearchIndex::class);
        $build = app(BuildIndexableProduct::class);

        $live = Product::factory()->status(ProductStatus::Published)->create(['title' => 'Copper Moka Pot']);
        $draft = Product::factory()->status(ProductStatus::Draft)->create(['title' => 'Copper Kettle Secret']);

        foreach ([$live, $draft] as $product) {
            $document = $build->describe($product->id);
            $this->assertNotNull($document);
            $index->index($document);
        }

        $this->assertSame([$live->id], $index->search('copper moka'));
        $this->assertSame([], $index->search('secret'), 'An unpublished product must not surface in search.');
    }

    #[Test]
    public function forgetting_a_product_is_safe_whether_it_was_indexed_or_not(): void
    {
        $index = app(SearchIndex::class);
        $product = Product::factory()->create();

        $index->forget($product->id);
        $index->forget(999_999);

        $this->assertDatabaseCount('product_search_documents', 0);
    }

    #[Test]
    public function the_proposing_seller_is_told_what_a_moderator_decided(): void
    {
        ['seller' => $seller, 'user' => $owner] = $this->makeSeller();
        $product = Product::factory()->proposedBy($seller->id)->create();

        app(DecideProduct::class)->requestChanges($product, $this->moderatorId, 'Please add the capacity to the title.');

        Notification::assertSentTo(
            $owner,
            ProductDecided::class,
            fn (ProductDecided $notification): bool => $notification->status === ProductStatus::ChangesRequested
                && $notification->reason === 'Please add the capacity to the title.',
        );
    }

    #[Test]
    public function a_product_the_platform_added_itself_notifies_nobody(): void
    {
        $product = Product::factory()->status(ProductStatus::PendingReview)->create([
            'created_by_seller_account_id' => null,
        ]);

        app(ApproveProduct::class)($product, $this->moderatorId);

        Notification::assertNothingSent();
    }

    #[Test]
    public function one_decision_produces_one_email_even_when_it_publishes_too(): void
    {
        ['seller' => $seller, 'user' => $owner] = $this->makeSeller();
        $product = Product::factory()->proposedBy($seller->id)->create();

        app(ApproveProduct::class)($product, $this->moderatorId, publish: true);

        Notification::assertSentToTimes($owner, ProductDecided::class, 1);
    }

    #[Test]
    public function every_catalogue_notification_is_queued(): void
    {
        $this->assertTrue(
            (new ReflectionClass(ProductDecided::class))
                ->implementsInterface(ShouldQueue::class),
            'A request must not wait on a mail server.',
        );
    }

    #[Test]
    public function an_uploaded_image_is_stored_immediately_and_processed_later(): void
    {
        Bus::fake([ProcessProductImage::class]);

        ['seller' => $seller] = $this->makeSeller();
        $product = Product::factory()->create();

        $media = app(AttachProductImage::class)(
            $product,
            UploadedFile::fake()->image('kettle.jpg', 1200, 1200),
            'seller',
            $seller->id,
            'A copper kettle on a slate worktop',
        );

        // The row exists at once so the seller sees their upload; the
        // expensive part is somebody else's problem.
        $this->assertSame(ProductMedia::STATE_PENDING, $media->processing_state);
        $this->assertFalse($media->isReady());
        $this->assertTrue($media->is_primary, 'The first image leads by default.');
        $this->assertSame(1200, $media->width);
        Storage::disk('media')->assertExists($media->path);

        Bus::assertDispatched(ProcessProductImage::class);
        $this->assertDatabaseHas('audit_logs', ['action' => 'catalogue.product.image_added']);
    }

    #[Test]
    public function processing_marks_the_image_ready_and_is_idempotent(): void
    {
        ['seller' => $seller] = $this->makeSeller();
        $product = Product::factory()->create();

        $media = app(AttachProductImage::class)(
            $product,
            UploadedFile::fake()->image('kettle.jpg', 800, 800),
            'seller',
            $seller->id,
        );

        app(ProcessProductImage::class, ['mediaId' => $media->id])->handle(app(ObjectStore::class));
        $this->assertSame(ProductMedia::STATE_READY, $media->refresh()->processing_state);

        $processedAt = $media->processed_at;
        app(ProcessProductImage::class, ['mediaId' => $media->id])->handle(app(ObjectStore::class));

        // A retry costs one read and changes nothing.
        $this->assertEquals($processedAt, $media->refresh()->processed_at);
    }

    #[Test]
    public function an_image_whose_bytes_have_gone_is_marked_failed_not_left_pending(): void
    {
        // The worker runs later, by which time the object may be gone —
        // so the automatic dispatch is held and the job run by hand after
        // the bytes disappear.
        Bus::fake([ProcessProductImage::class]);

        ['seller' => $seller] = $this->makeSeller();
        $product = Product::factory()->create();

        $media = app(AttachProductImage::class)(
            $product,
            UploadedFile::fake()->image('kettle.jpg', 400, 400),
            'seller',
            $seller->id,
        );

        app(ObjectStore::class)->delete(
            app(ObjectStore::class)->fromReference($media->reference(), Visibility::Public),
        );

        app(ProcessProductImage::class, ['mediaId' => $media->id])->handle(app(ObjectStore::class));

        // An image that never loads should say so, not look merely slow.
        $this->assertSame(ProductMedia::STATE_FAILED, $media->refresh()->processing_state);
    }

    #[Test]
    public function a_product_has_exactly_one_primary_image(): void
    {
        ['seller' => $seller] = $this->makeSeller();
        $product = Product::factory()->create();

        foreach (range(1, 3) as $ignored) {
            app(AttachProductImage::class)(
                $product,
                UploadedFile::fake()->image('shot.jpg', 600, 600),
                'seller',
                $seller->id,
            );
        }

        $this->assertSame(3, $product->media()->count());
        $this->assertSame(1, $product->media()->where('is_primary', true)->count());

        // And the database refuses a second, whatever wrote it.
        $this->expectException(QueryException::class);
        app('db')->table('product_media')
            ->where('product_id', $product->id)
            ->where('is_primary', false)
            ->limit(1)
            ->update(['is_primary' => true]);
    }

    #[Test]
    public function product_media_is_public_and_documents_are_not(): void
    {
        ['seller' => $seller] = $this->makeSeller();
        $product = Product::factory()->create();

        $media = app(AttachProductImage::class)(
            $product,
            UploadedFile::fake()->image('shot.jpg', 500, 500),
            'seller',
            $seller->id,
        );

        // Product photography is meant to be hotlinked and cached; seller
        // paperwork is not, and they live on different disks so the two
        // can never be confused.
        $this->assertSame(Visibility::Public, $media->visibility());
        $this->assertSame((string) config('veritas.storage.public_disk'), $media->disk);
        $this->assertNotSame((string) config('veritas.storage.private_disk'), $media->disk);
    }
}
