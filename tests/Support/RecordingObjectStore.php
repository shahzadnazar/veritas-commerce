<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Media\Contracts\ObjectStore;
use App\Modules\Media\Data\StoredObject;
use App\Modules\Media\Enums\Visibility;
use Illuminate\Http\UploadedFile;

/**
 * The real store, with a notebook.
 *
 * Two things are hard to prove about a private-file boundary from the
 * outside. The first is that an unauthorised request never causes a
 * capability to be MINTED — a 403 returned after a signed URL was created
 * has still put that URL into a log line, a trace and an APM span. The
 * second is that the link's lifetime is the configured one rather than a
 * literal somebody raised at a call site.
 *
 * Both are answered by recording what the application asked the store to
 * do, so the assertions are about behaviour rather than about a response
 * that looks the same either way.
 *
 * It also makes the signing branch reachable offline: a local disk cannot
 * sign, so without this the redirect path would only ever run against
 * real object storage.
 */
final class RecordingObjectStore implements ObjectStore
{
    /** @var array<int, array{key: string, seconds: int}> */
    public array $temporaryUrls = [];

    public function __construct(
        private readonly ObjectStore $inner,
        /** When set, this store can sign — as R2 can, and a local disk cannot. */
        private readonly ?string $signedUrlBase = null,
    ) {}

    /**
     * What the application asked to be signed, in order.
     *
     * A method rather than only the property, so a caller can hold the
     * list and index it — reading the property directly makes the type
     * checker (correctly) point out that it starts empty.
     *
     * @return array<int, array{key: string, seconds: int}>
     */
    public function minted(): array
    {
        return $this->temporaryUrls;
    }

    public function put(UploadedFile $file, string $collection, Visibility $visibility): StoredObject
    {
        return $this->inner->put($file, $collection, $visibility);
    }

    public function putContents(string $contents, string $collection, string $mime, Visibility $visibility): StoredObject
    {
        return $this->inner->putContents($contents, $collection, $mime, $visibility);
    }

    public function url(StoredObject $object): string
    {
        return $this->inner->url($object);
    }

    public function temporaryUrl(StoredObject $object, int $seconds): ?string
    {
        $this->temporaryUrls[] = ['key' => $object->key, 'seconds' => $seconds];

        if ($this->signedUrlBase === null) {
            return $this->inner->temporaryUrl($object, $seconds);
        }

        return $this->signedUrlBase.'/'.$object->key
            .'?X-Amz-Expires='.$seconds
            .'&X-Amz-Signature='.hash('sha256', $object->key.$seconds);
    }

    public function readStream(StoredObject $object)
    {
        return $this->inner->readStream($object);
    }

    public function exists(StoredObject $object): bool
    {
        return $this->inner->exists($object);
    }

    public function delete(StoredObject $object): void
    {
        $this->inner->delete($object);
    }

    public function fromReference(string $reference, Visibility $visibility): StoredObject
    {
        return $this->inner->fromReference($reference, $visibility);
    }
}
