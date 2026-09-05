<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Actions;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Media\Contracts\ObjectStore;
use App\Modules\Media\Data\StoredObject;
use App\Modules\Media\Enums\Visibility;
use App\Modules\Sellers\Enums\DocumentKind;
use App\Modules\Sellers\Models\SellerApplication;
use App\Modules\Sellers\Models\SellerApplicationDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
/**
 * Attaches a verification document to an application.
 *
 * Documents go to the private disk, which has no public URL and no route
 * that serves it. What is recorded is where the bytes went and what they
 * were — never the contents, and never anything that would let someone
 * without authorisation reconstruct a link.
 */ use Throwable;

final class UploadApplicationDocument
{
    public function __construct(
        private readonly ObjectStore $objects,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function __invoke(
        SellerApplication $application,
        UploadedFile $file,
        DocumentKind $kind,
        int $actorUserId,
    ): SellerApplicationDocument {
        // Outside the transaction: writing bytes is not something a
        // rollback can undo, and holding a transaction open across an
        // upload to remote storage is how connections get exhausted.
        $stored = $this->objects->put(
            $file,
            "sellers/applications/{$application->id}/documents",
            Visibility::Private,
        );

        $displayName = $this->safeName($file);

        try {
            return DB::transaction(function () use ($application, $stored, $kind, $actorUserId, $displayName): SellerApplicationDocument {
                $document = SellerApplicationDocument::query()->create([
                    'seller_application_id' => $application->id,
                    'kind' => $kind->value,
                    'disk' => $stored->disk,
                    'path' => $stored->key,
                    // Kept for the reviewer's benefit — "passport.pdf" tells
                    // them something — but never used to build a path.
                    'original_name' => $displayName,
                    'mime' => $stored->mime,
                    'bytes' => $stored->bytes,
                    'checksum' => $stored->checksum,
                    'uploaded_at' => Carbon::now(),
                ]);

                // The record names the document, not its contents: an audit
                // trail must never become the place a tax ID leaks.
                ($this->audit)(
                    action: 'seller.application.document_uploaded',
                    actorType: 'seller',
                    actorId: $actorUserId,
                    subjectType: SellerApplication::class,
                    subjectId: $application->id,
                    changes: [
                        'document_public_id' => $document->public_id,
                        'kind' => $kind->value,
                        'mime' => $stored->mime,
                        'bytes' => $stored->bytes,
                    ],
                );

                return $document;
            });
        } catch (Throwable $e) {
            $this->discard($stored);

            throw $e;
        }
    }

    /**
     * Remove bytes that no row will ever point at.
     *
     * The upload happens before the transaction, deliberately: writing to
     * remote storage is not something a rollback can undo, and holding a
     * database transaction open across it exhausts connections under
     * load. The cost of that choice is this window — the object is
     * written, the transaction fails, and a private identity document is
     * left sitting in a bucket with no record that it exists.
     *
     * That is the one orphan that is not merely wasted space. Nobody can
     * find it to delete it on request, it is outside every retention
     * sweep that works from the database, and it is exactly the kind of
     * data that should not accumulate quietly.
     *
     * Best effort, and it has to be: if the failure was storage being
     * away, the delete will fail too. Then it is logged with the key and
     * nothing else — never the filename, which is the applicant's — so an
     * operator can remove it by hand rather than never knowing it existed.
     */
    private function discard(StoredObject $stored): void
    {
        try {
            $this->objects->delete($stored);
        } catch (Throwable $e) {
            Log::error('An orphaned seller document could not be removed after a failed upload.', [
                'disk' => $stored->disk,
                'key' => $stored->key,
                'reason' => $e::class,
            ]);
        }
    }

    /** The uploader's name, made safe to display. */
    private function safeName(UploadedFile $file): string
    {
        $name = $file->getClientOriginalName();

        // Path separators and control characters stripped: this string is
        // rendered in an admin table, and it came from the internet.
        $clean = preg_replace('/[^\w \-.()]/u', '', str_replace(['/', '\\'], ' ', $name));

        return mb_substr(trim((string) $clean) ?: 'document', 0, 120);
    }
}
