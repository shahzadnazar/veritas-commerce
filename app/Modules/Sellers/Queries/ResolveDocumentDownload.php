<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Queries;

use App\Modules\Media\Contracts\ObjectStore;
use App\Modules\Media\Enums\Visibility;
use App\Modules\Sellers\Models\SellerApplicationDocument;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Hands one document to one already-authorised caller.
 *
 * Authorisation happens before this is reached — possession of a URL is
 * never what grants access. What this decides is only *how* the bytes
 * travel: a remote disk can sign a short-lived link so they never pass
 * through the application server, and a local disk cannot, so they are
 * streamed.
 *
 * Either way the link is not durable and not shareable beyond its expiry.
 */
final class ResolveDocumentDownload
{
    public function __construct(private readonly ObjectStore $objects) {}

    public function __invoke(SellerApplicationDocument $document): Response
    {
        $object = $this->objects->fromReference($document->disk.':'.$document->path, Visibility::Private);

        if (! $this->objects->exists($object)) {
            throw new RuntimeException('That document is no longer in storage.');
        }

        $signed = $this->objects->temporaryUrl($object, (int) config('veritas.storage.signed_url_seconds'));

        if ($signed !== null) {
            return redirect()->away($signed);
        }

        $stream = $this->objects->readStream($object);

        if ($stream === null) {
            throw new RuntimeException('That document could not be read.');
        }

        return new StreamedResponse(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, Response::HTTP_OK, [
            'Content-Type' => $document->mime,
            // Attachment, not inline: a PDF or an image rendered in the
            // page would run in the application's own origin.
            'Content-Disposition' => 'attachment; filename="'.addslashes($document->original_name).'"',
            'Content-Length' => (string) $document->bytes,
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
