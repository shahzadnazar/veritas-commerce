<?php

declare(strict_types=1);

namespace App\Modules\Media\Stores;

use App\Modules\Media\Contracts\ObjectStore;
use App\Modules\Media\Data\StoredObject;
use App\Modules\Media\Enums\Visibility;
use App\Modules\Media\Exceptions\RejectedUpload;
use App\Modules\Media\Support\UploadPolicy;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The object store, over Laravel's filesystem abstraction.
 *
 * One implementation serves every environment: the local disk in
 * development and S3-compatible object storage in production, chosen by
 * configuration. Nothing here names a vendor, and nothing above here knows
 * a filesystem is involved at all.
 *
 * Keys are generated. The uploader's filename never becomes part of a
 * path, which removes traversal, collision and the occasional executable
 * extension in one move.
 */
final class FilesystemObjectStore implements ObjectStore
{
    public function put(UploadedFile $file, string $collection, Visibility $visibility): StoredObject
    {
        $policy = $this->policyFor($visibility);
        $inspected = $policy->inspect($file);

        $path = $file->getRealPath();

        if ($path === false) {
            throw new RejectedUpload('That file could not be read.');
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RejectedUpload('That file could not be read.');
        }

        return $this->write(
            contents: $contents,
            collection: $collection,
            mime: $inspected['mime'],
            visibility: $visibility,
            extension: $policy->extensionFor($inspected['mime']),
            width: $inspected['width'],
            height: $inspected['height'],
        );
    }

    public function putContents(
        string $contents,
        string $collection,
        string $mime,
        Visibility $visibility,
    ): StoredObject {
        return $this->write(
            contents: $contents,
            collection: $collection,
            mime: $mime,
            visibility: $visibility,
            extension: $this->policyFor($visibility)->extensionFor($mime),
        );
    }

    public function url(StoredObject $object): string
    {
        if ($object->visibility === Visibility::Private) {
            throw new RuntimeException(
                'A private object has no public URL. Use temporaryUrl(), or stream it after an authorisation check.'
            );
        }

        return $this->disk($object->disk)->url($object->key);
    }

    public function temporaryUrl(StoredObject $object, int $seconds): ?string
    {
        try {
            return $this->disk($object->disk)->temporaryUrl($object->key, now()->addSeconds($seconds));
        } catch (Throwable) {
            // A local disk cannot sign. That is not an error — the caller
            // streams the bytes itself instead.
            return null;
        }
    }

    /** @return resource|null */
    public function readStream(StoredObject $object)
    {
        $stream = $this->disk($object->disk)->readStream($object->key);

        return is_resource($stream) ? $stream : null;
    }

    public function exists(StoredObject $object): bool
    {
        return $this->disk($object->disk)->exists($object->key);
    }

    public function delete(StoredObject $object): void
    {
        $this->disk($object->disk)->delete($object->key);
    }

    public function fromReference(string $reference, Visibility $visibility): StoredObject
    {
        $separator = strpos($reference, ':');

        if ($separator === false) {
            throw new RuntimeException("Malformed object reference: {$reference}");
        }

        return new StoredObject(
            disk: substr($reference, 0, $separator),
            key: substr($reference, $separator + 1),
            mime: 'application/octet-stream',
            bytes: 0,
            visibility: $visibility,
        );
    }

    private function policyFor(Visibility $visibility): UploadPolicy
    {
        return $visibility === Visibility::Private
            ? UploadPolicy::forDocuments()
            : UploadPolicy::forImages();
    }

    private function write(
        string $contents,
        string $collection,
        string $mime,
        Visibility $visibility,
        string $extension,
        ?int $width = null,
        ?int $height = null,
    ): StoredObject {
        $disk = $visibility->disk();

        // A ULID sorts by creation time, which makes a bucket listing
        // readable, and carries nothing about who uploaded it.
        $key = trim($collection, '/').'/'.Str::lower((string) Str::ulid()).'.'.$extension;

        $written = $this->disk($disk)->put($key, $contents, [
            'visibility' => $visibility->value,
            'ContentType' => $mime,
        ]);

        if ($written === false) {
            throw new RuntimeException("Could not write {$key} to the {$disk} disk.");
        }

        return new StoredObject(
            disk: $disk,
            key: $key,
            mime: $mime,
            bytes: strlen($contents),
            visibility: $visibility,
            // Lets a later job tell "already processed" from "changed", and
            // a support engineer tell two similar files apart.
            checksum: hash('sha256', $contents),
            width: $width,
            height: $height,
        );
    }

    private function disk(string $name): Filesystem
    {
        return Storage::disk($name);
    }
}
