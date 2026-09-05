<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Queries;

use App\Modules\Media\Contracts\ObjectStore;
use App\Modules\Media\Enums\Visibility;
use App\Modules\Sellers\Models\SellerApplicationDocument;
use RuntimeException;
use Symfony\Component\HttpFoundation\HeaderUtils;
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
            'Content-Disposition' => $this->disposition($document->original_name),
            'Content-Length' => (string) $document->bytes,
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * A filename, made safe to be a header value.
     *
     * The upload path already strips path separators and control
     * characters from what it stores, so nothing hostile reaches here
     * today. This is the second lock on the same door, and it is worth
     * having because the two are far apart: a backfill, an importer or an
     * admin correction that wrote `original_name` without going through
     * the uploader would turn a stored string into a response header, and
     * a stored `\r\n` in a response header is somebody else's headers.
     *
     * Symfony's builder does the RFC 6266 work — quoting, and the
     * `filename*=UTF-8''…` form for non-ASCII names — but it refuses a
     * fallback containing anything outside ASCII, so the fallback is
     * reduced to a plain name here rather than left to throw on a
     * download somebody legitimately needs.
     */
    private function disposition(string $originalName): string
    {
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $originalName);
        $name = str_replace(['/', '\\', '%', '"'], ' ', $name);
        $name = mb_substr(trim($name), 0, 120);

        $fallback = (string) preg_replace('/[^A-Za-z0-9._\- ]/', '', $name);
        $fallback = trim($fallback);

        return HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $name === '' ? 'document' : $name,
            $fallback === '' ? 'document' : $fallback,
        );
    }
}
