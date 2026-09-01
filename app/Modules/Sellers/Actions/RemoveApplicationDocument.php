<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Actions;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Media\Contracts\ObjectStore;
use App\Modules\Media\Enums\Visibility;
use App\Modules\Sellers\Models\SellerApplication;
use App\Modules\Sellers\Models\SellerApplicationDocument;
use Illuminate\Support\Facades\DB;

/**
 * Removes a document, and the bytes behind it.
 *
 * The row goes first, inside a transaction; the object is deleted after it
 * commits. Doing it the other way round risks a record pointing at
 * nothing, which reads to a reviewer as a document that was never
 * uploaded rather than one that was withdrawn.
 */
final class RemoveApplicationDocument
{
    public function __construct(
        private readonly ObjectStore $objects,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function __invoke(SellerApplicationDocument $document, string $actorType, int $actorId): void
    {
        $reference = $document->disk.':'.$document->path;
        $applicationId = $document->seller_application_id;
        $publicId = $document->public_id;
        $kind = $document->kind;

        DB::transaction(function () use ($document, $applicationId, $publicId, $kind, $actorType, $actorId): void {
            ($this->audit)(
                action: 'seller.application.document_removed',
                actorType: $actorType,
                actorId: $actorId,
                subjectType: SellerApplication::class,
                subjectId: $applicationId,
                changes: ['document_public_id' => $publicId, 'kind' => $kind],
            );

            $document->delete();
        });

        DB::afterCommit(function () use ($reference): void {
            $this->objects->delete($this->objects->fromReference($reference, Visibility::Private));
        });
    }
}
