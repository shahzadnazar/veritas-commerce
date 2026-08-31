<?php

declare(strict_types=1);

namespace App\Modules\Media\Actions;

use App\Modules\Media\Contracts\MediaStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Writes an uploaded image through the storage abstraction.
 *
 * Two rules that are not negotiable:
 *
 *  1. The type is decided by the file's contents, never by its extension.
 *     An attacker renames; a magic-byte check does not care.
 *  2. The stored path is generated. Keeping the uploader's filename invites
 *     traversal, collision and the occasional executable extension.
 */
final class StoreUploadedImage implements MediaStore
{
    public function put(UploadedFile $file, string $collection): array
    {
        $mime = $this->sniffMime($file);
        $accepted = (array) config('veritas.media.accepted_mimes');

        if (! in_array($mime, $accepted, true)) {
            throw new RuntimeException("Files of type {$mime} are not accepted.");
        }

        $maxBytes = ((int) config('veritas.media.max_upload_kb')) * 1024;

        if ($file->getSize() > $maxBytes) {
            throw new RuntimeException('That file is larger than the upload limit.');
        }

        $disk = (string) config('veritas.media.disk');
        $extension = match ($mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };

        $path = $collection.'/'.Str::ulid().'.'.$extension;

        Storage::disk($disk)->put($path, (string) file_get_contents($file->getRealPath()));

        return [
            'disk' => $disk,
            'path' => $path,
            'mime' => $mime,
            'bytes' => (int) $file->getSize(),
        ];
    }

    public function url(string $disk, string $path): string
    {
        return Storage::disk($disk)->url($path);
    }

    public function delete(string $disk, string $path): void
    {
        Storage::disk($disk)->delete($path);
    }

    /**
     * Reads the type from the file itself.
     *
     * getClientMimeType() is whatever the browser claimed; this is what the
     * bytes actually say.
     */
    private function sniffMime(UploadedFile $file): string
    {
        $detected = @mime_content_type($file->getRealPath());

        if ($detected === false) {
            throw new RuntimeException('That file could not be read as an image.');
        }

        return $detected;
    }

    /**
     * @return array{width: int, height: int}
     *
     * @throws RuntimeException when the file is not a readable image
     */
    public function dimensions(UploadedFile $file): array
    {
        $size = @getimagesize($file->getRealPath());

        if ($size === false) {
            throw new RuntimeException('That file is not a readable image.');
        }

        return ['width' => $size[0], 'height' => $size[1]];
    }
}
