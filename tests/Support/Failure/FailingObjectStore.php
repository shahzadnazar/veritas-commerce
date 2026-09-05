<?php

declare(strict_types=1);

namespace Tests\Support\Failure;

use App\Modules\Media\Contracts\ObjectStore;
use App\Modules\Media\Data\StoredObject;
use App\Modules\Media\Enums\Visibility;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Object storage that fails on the operations named, and behaves for the
 * rest.
 *
 * A decorator rather than a stub, because the drills need the other
 * operations to keep working: proving that a failed *write* leaves no
 * misleading database row is only interesting if reads, deletes and
 * signed URLs are still real. The single-method failure is also what a
 * provider outage actually looks like from the application's side —
 * `PutObject` returning 503 while a `GetObject` on a cached edge still
 * succeeds.
 *
 * Records what it was asked to do, so a drill can ask the harder
 * question: not "did the request fail" but "was the object written before
 * the failure that discarded the row that pointed at it".
 */
final class FailingObjectStore implements ObjectStore
{
    /** @var array<int, string> */
    public array $calls = [];

    /** @param array<int, string> $failing */
    public function __construct(
        private readonly ObjectStore $inner,
        private readonly array $failing,
    ) {}

    public function put(UploadedFile $file, string $collection, Visibility $visibility): StoredObject
    {
        $this->guard('put');

        return $this->inner->put($file, $collection, $visibility);
    }

    public function putContents(
        string $contents,
        string $collection,
        string $mime,
        Visibility $visibility,
    ): StoredObject {
        $this->guard('putContents');

        return $this->inner->putContents($contents, $collection, $mime, $visibility);
    }

    public function url(StoredObject $object): string
    {
        $this->guard('url');

        return $this->inner->url($object);
    }

    public function temporaryUrl(StoredObject $object, int $seconds): ?string
    {
        $this->guard('temporaryUrl');

        return $this->inner->temporaryUrl($object, $seconds);
    }

    public function readStream(StoredObject $object)
    {
        $this->guard('readStream');

        return $this->inner->readStream($object);
    }

    public function exists(StoredObject $object): bool
    {
        $this->guard('exists');

        return $this->inner->exists($object);
    }

    public function delete(StoredObject $object): void
    {
        $this->guard('delete');

        $this->inner->delete($object);
    }

    public function fromReference(string $reference, Visibility $visibility): StoredObject
    {
        return $this->inner->fromReference($reference, $visibility);
    }

    private function guard(string $operation): void
    {
        $this->calls[] = $operation;

        if (in_array($operation, $this->failing, true)) {
            throw new RuntimeException("Object storage is unavailable: {$operation} failed.");
        }
    }
}
