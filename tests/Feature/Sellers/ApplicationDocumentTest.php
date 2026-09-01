<?php

declare(strict_types=1);

namespace Tests\Feature\Sellers;

use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Identity\Models\User;
use App\Modules\Sellers\Actions\UploadApplicationDocument;
use App\Modules\Sellers\Enums\DocumentKind;
use App\Modules\Sellers\Enums\SellerApplicationStatus;
use App\Modules\Sellers\Models\SellerApplication;
use App\Modules\Sellers\Models\SellerApplicationDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Verification paperwork: who may upload it, who may read it, and who may
 * not — tested by making the request anyway.
 */
final class ApplicationDocumentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        // Mirrors the real disk: private, and with no route that serves
        // it, so the download path streams rather than redirecting.
        Storage::fake('documents', ['visibility' => 'private', 'serve' => false]);
        Storage::fake('media');
    }

    #[Test]
    public function an_applicant_can_attach_a_document_to_their_own_application(): void
    {
        $user = User::factory()->create();
        $application = SellerApplication::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user, 'web')
            ->post('/seller/apply/documents', [
                'kind' => DocumentKind::BusinessRegistration->value,
                'document' => $this->pdf(),
            ])
            ->assertRedirect();

        $document = SellerApplicationDocument::query()->firstOrFail();

        $this->assertSame($application->id, $document->seller_application_id);
        $this->assertSame('documents', $document->disk);
        $this->assertSame('application/pdf', $document->mime);
        Storage::disk('documents')->assertExists($document->path);
    }

    #[Test]
    public function the_stored_path_keeps_nothing_of_the_uploaded_filename(): void
    {
        $user = User::factory()->create();
        SellerApplication::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user, 'web')->post('/seller/apply/documents', [
            'kind' => DocumentKind::TaxRegistration->value,
            'document' => $this->pdf('../../secret-tax-return.pdf'),
        ]);

        $document = SellerApplicationDocument::query()->firstOrFail();

        $this->assertStringNotContainsString('secret-tax-return', $document->path);
        $this->assertStringNotContainsString('..', $document->path);
        $this->assertMatchesRegularExpression('#^sellers/applications/\d+/documents/[0-9a-z]{26}\.pdf$#', $document->path);

        // The original name survives for the reviewer to read, with the
        // path separators taken out of it.
        $this->assertStringNotContainsString('/', $document->original_name);
    }

    #[Test]
    public function a_document_is_not_reachable_without_signing_in(): void
    {
        $document = $this->uploadedDocument();

        $this->get("/seller/apply/documents/{$document->public_id}")->assertRedirect('/login');
        $this->get("/admin/applications/documents/{$document->public_id}")->assertRedirect('/admin/login');
    }

    #[Test]
    public function the_private_disk_publishes_no_url_for_a_document(): void
    {
        $this->uploadedDocument();

        // Nothing to guess and nothing to leak: the disk itself has no
        // public address, so there is no URL to be careless with.
        $this->assertNull(config('filesystems.disks.documents.url'));
        $this->assertSame('private', config('filesystems.disks.documents.visibility'));
    }

    #[Test]
    public function an_applicant_cannot_read_another_applicants_document(): void
    {
        $theirs = $this->uploadedDocument();

        $stranger = User::factory()->create();
        SellerApplication::factory()->create(['user_id' => $stranger->id]);

        // A real id, a direct request, and a signed-in account: the only
        // thing missing is ownership.
        $this->actingAs($stranger, 'web')
            ->get("/seller/apply/documents/{$theirs->public_id}")
            ->assertNotFound();
    }

    #[Test]
    public function an_applicant_cannot_delete_another_applicants_document(): void
    {
        $theirs = $this->uploadedDocument();

        $stranger = User::factory()->create();
        SellerApplication::factory()->create(['user_id' => $stranger->id]);

        $this->actingAs($stranger, 'web')
            ->delete("/seller/apply/documents/{$theirs->public_id}")
            ->assertNotFound();

        $this->assertDatabaseHas('seller_application_documents', ['id' => $theirs->id]);
        Storage::disk('documents')->assertExists($theirs->path);
    }

    #[Test]
    public function an_applicant_with_no_application_of_their_own_gets_nothing(): void
    {
        $theirs = $this->uploadedDocument();
        $customer = User::factory()->create();

        $this->actingAs($customer, 'web')
            ->get("/seller/apply/documents/{$theirs->public_id}")
            ->assertNotFound();
    }

    #[Test]
    public function an_applicant_can_read_and_remove_their_own(): void
    {
        $user = User::factory()->create();
        $document = $this->uploadedDocument($user);

        $this->assertHandsOverTheBytes(
            $this->actingAs($user, 'web')->get("/seller/apply/documents/{$document->public_id}"),
        );

        $this->actingAs($user, 'web')
            ->delete("/seller/apply/documents/{$document->public_id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('seller_application_documents', ['id' => $document->id]);
        Storage::disk('documents')->assertMissing($document->path);
    }

    #[Test]
    public function a_cleared_reviewer_can_read_any_applicants_document(): void
    {
        $document = $this->uploadedDocument();

        $this->assertHandsOverTheBytes(
            $this->actingAs($this->makeAdmin(AdminRole::SellerOperations), 'admin')
                ->get("/admin/applications/documents/{$document->public_id}"),
        );
    }

    #[Test]
    public function a_reviewer_without_the_sensitive_permission_is_refused(): void
    {
        $document = $this->uploadedDocument();

        // Support can see an application; paperwork carries the same class
        // of information as a tax ID and needs the same clearance.
        $this->actingAs($this->makeAdmin(AdminRole::Support), 'admin')
            ->get("/admin/applications/documents/{$document->public_id}")
            ->assertForbidden();
    }

    #[Test]
    public function a_seller_session_cannot_reach_the_admin_download(): void
    {
        $document = $this->uploadedDocument();
        ['user' => $seller] = $this->makeSeller();

        $this->actingAs($seller, 'web')
            ->get("/admin/applications/documents/{$document->public_id}")
            ->assertRedirect('/admin/login');
    }

    #[Test]
    public function an_executable_renamed_as_a_pdf_is_refused(): void
    {
        $user = User::factory()->create();
        SellerApplication::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user, 'web')
            ->post('/seller/apply/documents', [
                'kind' => DocumentKind::BusinessRegistration->value,
                'document' => $this->fakeFile('registration.pdf', "#!/bin/sh\necho pwned\n"),
            ])
            ->assertSessionHasErrors('document');

        $this->assertSame(0, SellerApplicationDocument::query()->count());
    }

    #[Test]
    public function an_oversized_document_is_refused_before_it_reaches_storage(): void
    {
        config(['veritas.storage.max_document_kb' => 8]);

        $user = User::factory()->create();
        SellerApplication::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user, 'web')
            ->post('/seller/apply/documents', [
                'kind' => DocumentKind::BankStatement->value,
                'document' => UploadedFile::fake()->create('statement.pdf', 64, 'application/pdf'),
            ])
            ->assertSessionHasErrors('document');

        $this->assertSame(0, SellerApplicationDocument::query()->count());
    }

    #[Test]
    public function an_unknown_document_kind_is_refused(): void
    {
        $user = User::factory()->create();
        SellerApplication::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user, 'web')
            ->post('/seller/apply/documents', ['kind' => 'passport-ish', 'document' => $this->pdf()])
            ->assertSessionHasErrors('kind');
    }

    #[Test]
    public function a_decided_application_accepts_no_further_documents(): void
    {
        $user = User::factory()->create();
        SellerApplication::factory()
            ->status(SellerApplicationStatus::Approved)
            ->create(['user_id' => $user->id]);

        $this->actingAs($user, 'web')
            ->post('/seller/apply/documents', [
                'kind' => DocumentKind::BusinessRegistration->value,
                'document' => $this->pdf(),
            ])
            ->assertRedirect();

        $this->assertSame(0, SellerApplicationDocument::query()->count());
    }

    #[Test]
    public function uploading_and_removing_are_both_audited_without_the_contents(): void
    {
        $user = User::factory()->create();
        $document = $this->uploadedDocument($user);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'seller.application.document_uploaded',
            'actor_type' => 'seller',
            'actor_id' => $user->id,
        ]);

        $this->actingAs($user, 'web')->delete("/seller/apply/documents/{$document->public_id}");

        $this->assertDatabaseHas('audit_logs', ['action' => 'seller.application.document_removed']);

        // The record names the document. It never carries a path anyone
        // could replay, nor anything read out of the file.
        $entries = app('db')->table('audit_logs')
            ->whereIn('action', ['seller.application.document_uploaded', 'seller.application.document_removed'])
            ->pluck('changes')
            ->implode(' ');

        $this->assertStringNotContainsString($document->path, $entries);
        $this->assertStringNotContainsString('documents/', $entries);
    }

    #[Test]
    public function a_disk_that_can_sign_hands_over_an_expiring_link_instead(): void
    {
        $user = User::factory()->create();
        $document = $this->uploadedDocument($user);

        // Production points this disk at object storage, which signs. The
        // bytes then never pass through the application at all, and the
        // link stops working shortly afterwards.
        Storage::fake('documents', ['serve' => true]);
        Storage::disk('documents')->put($document->path, 'pdf-bytes');

        $this->actingAs($user, 'web')
            ->get("/seller/apply/documents/{$document->public_id}")
            ->assertRedirect()
            ->assertRedirectContains('expir');
    }

    /**
     * Attach a document without signing anyone in.
     *
     * Going through the action rather than the route keeps the helper from
     * establishing a session, so a test about what a guest can reach is
     * actually testing a guest.
     */
    private function uploadedDocument(?User $user = null): SellerApplicationDocument
    {
        $user ??= User::factory()->create();
        $application = SellerApplication::factory()->create(['user_id' => $user->id]);

        return app(UploadApplicationDocument::class)(
            application: $application,
            file: $this->pdf(),
            kind: DocumentKind::BusinessRegistration,
            actorUserId: $user->id,
        );
    }

    /**
     * The document reached the caller, one way or the other.
     *
     * A disk that can sign hands over a short-lived link so the bytes never
     * pass through the application; one that cannot streams them. What must
     * never happen is a durable, guessable URL — so a redirect is only
     * accepted when it expires.
     */
    private function assertHandsOverTheBytes(TestResponse $response): void
    {
        if ($response->isRedirect()) {
            // A redirect to a document must be to a link that expires.
            $response->assertRedirectContains('expir');

            return;
        }

        $response->assertOk();
        $response->assertHeader('Content-Disposition', 'attachment; filename="registration.pdf"');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    private function pdf(string $name = 'registration.pdf'): UploadedFile
    {
        return $this->fakeFile($name, "%PDF-1.4\n1 0 obj\n<< >>\nendobj\ntrailer\n<< >>\n%%EOF\n");
    }

    private function fakeFile(string $name, string $contents): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'vc-doc');
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, null, null, true);
    }
}
