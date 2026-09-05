<?php

declare(strict_types=1);

namespace Tests\Feature\Failure;

use App\Modules\Catalog\Actions\AttachProductImage;
use App\Modules\Catalog\Models\Product;
use App\Modules\Media\Contracts\ObjectStore;
use App\Modules\Media\Enums\Visibility;
use App\Modules\Sellers\Actions\UploadApplicationDocument;
use App\Modules\Sellers\Enums\DocumentKind;
use App\Modules\Sellers\Models\SellerApplication;
use App\Modules\Sellers\Models\SellerApplicationDocument;
use App\Modules\Sellers\Queries\ResolveDocumentDownload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Failure\BreaksInfrastructure;
use Tests\Support\Failure\FailsAtQuery;
use Tests\TestCase;
use Throwable;

/**
 * Object storage fails. Nothing may end up pointing at bytes that are
 * not there, and nothing private may end up anywhere it should not be.
 *
 * Two directions matter, and they are not symmetric. A database row
 * claiming a document that was never stored is a broken promise a
 * reviewer discovers at the worst moment. An object stored with no row
 * pointing at it is worse in one specific case: a private identity
 * document nobody can find to delete, outside every retention sweep that
 * works from the database.
 */
final class ObjectStorageFailureTest extends TestCase
{
    use BreaksInfrastructure;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('private');
    }

    private function image(): UploadedFile
    {
        return UploadedFile::fake()->image('kettle.jpg', 600, 600);
    }

    private function document(): UploadedFile
    {
        // Real bytes, not a zero-length placeholder: the object store
        // rejects an empty upload, which is the correct behaviour and not
        // the one under test here.
        return UploadedFile::fake()->createWithContent(
            'passport.pdf',
            "%PDF-1.4\n".str_repeat('drill ', 200),
        );
    }

    // ---------------------------------------------------------------
    // A write that fails leaves no row.
    // ---------------------------------------------------------------

    #[Test]
    public function a_failed_media_write_leaves_no_media_row(): void
    {
        $product = Product::factory()->create();

        $this->withObjectStoreFailing(['put'], function () use ($product): void {
            $raised = false;

            try {
                app(AttachProductImage::class)($product, $this->image(), 'admin', 1);
            } catch (Throwable) {
                $raised = true;
            }

            $this->assertTrue($raised, 'A failed upload appeared to succeed.');
        });

        $this->assertDatabaseCount('product_media', 0);
    }

    #[Test]
    public function a_failed_document_write_leaves_no_document_row(): void
    {
        $application = SellerApplication::factory()->create();

        $this->withObjectStoreFailing(['put'], function () use ($application): void {
            $raised = false;

            try {
                app(UploadApplicationDocument::class)(
                    $application,
                    $this->document(),
                    DocumentKind::IdentityDocument,
                    actorUserId: 1,
                );
            } catch (Throwable) {
                $raised = true;
            }

            $this->assertTrue($raised, 'A failed document upload appeared to succeed.');
        });

        $this->assertDatabaseCount('seller_application_documents', 0);
    }

    // ---------------------------------------------------------------
    // A write that succeeds and is then rolled back leaves no object.
    // ---------------------------------------------------------------

    /**
     * The orphan case, and the one that matters most.
     *
     * The bytes are written before the transaction opens — necessarily,
     * because a rollback cannot unwrite them and holding a transaction
     * across a remote upload exhausts connections. So when the
     * transaction fails, a private identity document exists with nothing
     * referencing it: invisible to every retention sweep, and impossible
     * to find if the applicant asks for it to be deleted.
     */
    #[Test]
    public function a_document_upload_that_rolls_back_removes_the_stored_object(): void
    {
        $application = SellerApplication::factory()->create();

        FailsAtQuery::containing('insert into "seller_application_documents"');

        $raised = false;

        try {
            app(UploadApplicationDocument::class)(
                $application,
                $this->document(),
                DocumentKind::IdentityDocument,
                actorUserId: 1,
            );
        } catch (Throwable) {
            $raised = true;
        }

        $this->assertTrue($raised);
        $this->assertDatabaseCount('seller_application_documents', 0);
        $this->assertSame(
            [],
            Storage::disk('private')->allFiles(),
            'A private document was left in storage with no row pointing at it.',
        );
    }

    #[Test]
    public function a_media_upload_that_rolls_back_removes_the_stored_object(): void
    {
        $product = Product::factory()->create();

        FailsAtQuery::containing('insert into "product_media"');

        try {
            app(AttachProductImage::class)($product, $this->image(), 'admin', 1);
        } catch (Throwable) {
            // Expected.
        }

        $this->assertDatabaseCount('product_media', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    /**
     * And when the cleanup itself cannot run, the failure is recorded.
     *
     * Storage being away is the likeliest reason the transaction failed
     * in the first place, so the compensating delete failing is the
     * expected case rather than the exotic one. What must not happen is
     * that it disappears — an operator needs the key to remove it by
     * hand.
     */
    #[Test]
    public function an_uncleanable_orphan_is_reported_rather_than_forgotten(): void
    {
        $application = SellerApplication::factory()->create();

        FailsAtQuery::containing('insert into "seller_application_documents"');

        $this->withObjectStoreFailing(['delete'], function () use ($application): void {
            try {
                app(UploadApplicationDocument::class)(
                    $application,
                    $this->document(),
                    DocumentKind::IdentityDocument,
                    actorUserId: 1,
                );
            } catch (Throwable) {
                // Expected: the original failure is rethrown, not the
                // cleanup's.
            }
        });

        $this->assertDatabaseCount('seller_application_documents', 0);
    }

    // ---------------------------------------------------------------
    // Reads.
    // ---------------------------------------------------------------

    /**
     * A read failure is a failure, not a fallback.
     *
     * The dangerous shape here would be a resolver that, unable to stream
     * or sign, reached for the object's public URL — turning a storage
     * incident into a permanent unauthenticated link to somebody's
     * passport.
     */
    #[Test]
    public function a_signed_url_failure_never_falls_back_to_a_public_url(): void
    {
        $document = $this->storedDocument();

        $this->withObjectStoreFailing(['temporaryUrl'], function () use ($document): void {
            $raised = false;

            try {
                app(ResolveDocumentDownload::class)($document);
            } catch (Throwable $e) {
                $raised = true;

                $this->assertStringNotContainsString(
                    'http',
                    $e->getMessage(),
                    'The failure carried a URL out of the resolver.',
                );
            }

            $this->assertTrue($raised, 'The resolver produced a download when signing had failed.');
        });
    }

    /**
     * The same, one layer down: when signing is simply unavailable rather
     * than broken, the resolver streams through the application — which
     * is authenticated — and never hands out a durable link.
     */
    #[Test]
    public function an_unsignable_object_is_streamed_rather_than_published(): void
    {
        $document = $this->storedDocument();

        $response = app(ResolveDocumentDownload::class)($document);

        $this->assertFalse(
            $response->isRedirect(),
            'A local private object was answered with a redirect to a URL.',
        );
        // Directives, not their order: Symfony normalises the header.
        foreach (['private', 'no-store', 'max-age=0'] as $directive) {
            $this->assertStringContainsString($directive, (string) $response->headers->get('Cache-Control'));
        }
    }

    /**
     * A read that fails discloses nothing about the provider.
     *
     * Bucket names, endpoints and keys are all things an error message
     * would happily carry to a browser.
     */
    #[Test]
    public function a_read_failure_leaks_no_provider_internals(): void
    {
        $document = $this->storedDocument();

        $this->withObjectStoreFailing(['readStream'], function () use ($document): void {
            try {
                app(ResolveDocumentDownload::class)($document);
                $this->fail('A failed read produced a response.');
            } catch (Throwable $e) {
                foreach (['veritas-private', 'r2.cloudflarestorage', 'AKIA', 'secret'] as $leak) {
                    $this->assertStringNotContainsString($leak, $e->getMessage());
                }
            }
        });
    }

    /** A document whose bytes really are in the fake private disk. */
    private function storedDocument(): SellerApplicationDocument
    {
        $application = SellerApplication::factory()->create();

        /** @var ObjectStore $store */
        $store = app(ObjectStore::class);
        $stored = $store->put($this->document(), 'sellers/applications/1/documents', Visibility::Private);

        return SellerApplicationDocument::query()->create([
            'seller_application_id' => $application->id,
            'kind' => DocumentKind::IdentityDocument->value,
            'disk' => $stored->disk,
            'path' => $stored->key,
            'original_name' => 'passport.pdf',
            'mime' => $stored->mime,
            'bytes' => $stored->bytes,
            'checksum' => $stored->checksum,
            'uploaded_at' => now(),
        ]);
    }
}
