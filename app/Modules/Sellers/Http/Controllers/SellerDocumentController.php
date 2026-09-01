<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Http\Controllers;

use App\Modules\Media\Exceptions\RejectedUpload;
use App\Modules\Sellers\Actions\RemoveApplicationDocument;
use App\Modules\Sellers\Actions\UploadApplicationDocument;
use App\Modules\Sellers\Enums\DocumentKind;
use App\Modules\Sellers\Models\SellerApplication;
use App\Modules\Sellers\Models\SellerApplicationDocument;
use App\Modules\Sellers\Queries\ResolveDocumentDownload;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The applicant's side of verification paperwork.
 *
 * Every route resolves the application from the signed-in user, never from
 * an id in the request, so there is nothing to substitute. A document id
 * that belongs to somebody else's application simply does not resolve.
 */
final class SellerDocumentController
{
    public function __construct(
        private readonly UploadApplicationDocument $upload,
        private readonly RemoveApplicationDocument $remove,
        private readonly ResolveDocumentDownload $download,
    ) {}

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'kind' => ['required', Rule::enum(DocumentKind::class)],
            // Server-side limits as well as the store's own: a request
            // that never reaches the store should still be refused.
            'document' => ['required', 'file', 'max:'.(int) config('veritas.storage.max_document_kb')],
        ]);

        $application = $this->ownApplication($request);
        $user = $request->user('web');
        abort_if($user === null, 403);

        if (! $this->acceptsDocuments($application)) {
            return back()->with('error', 'This application has been decided and takes no further documents.');
        }

        try {
            ($this->upload)(
                application: $application,
                file: $request->file('document'),
                kind: DocumentKind::from($validated['kind']),
                actorUserId: $user->getAuthIdentifier(),
            );
        } catch (RejectedUpload $exception) {
            throw ValidationException::withMessages(['document' => $exception->getMessage()]);
        }

        return back()->with('success', 'Document uploaded.');
    }

    public function show(Request $request, string $publicId): Response
    {
        // Scoped to the applicant's own application, so another
        // applicant's document id resolves to nothing.
        return ($this->download)($this->ownDocument($request, $publicId));
    }

    public function destroy(Request $request, string $publicId): RedirectResponse
    {
        $application = $this->ownApplication($request);
        $user = $request->user('web');
        abort_if($user === null, 403);

        if (! $this->acceptsDocuments($application)) {
            return back()->with('error', 'This application has been decided and its documents are part of the record.');
        }

        ($this->remove)($this->ownDocument($request, $publicId), 'seller', $user->getAuthIdentifier());

        return back()->with('success', 'Document removed.');
    }

    /**
     * Paperwork is collected while an application is live, not only while
     * it is editable: a reviewer who opens an application and asks for the
     * registration certificate expects the applicant to be able to send
     * it without the form reopening. A decided application is closed.
     */
    private function acceptsDocuments(SellerApplication $application): bool
    {
        return ! $application->status->isTerminal();
    }

    private function ownApplication(Request $request): SellerApplication
    {
        $user = $request->user('web');
        abort_if($user === null, 403);

        $application = SellerApplication::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->latest('id')
            ->first();

        abort_if($application === null, 404);

        return $application;
    }

    private function ownDocument(Request $request, string $publicId): SellerApplicationDocument
    {
        return SellerApplicationDocument::query()
            ->where('seller_application_id', $this->ownApplication($request)->id)
            ->where('public_id', $publicId)
            ->firstOrFail();
    }
}
