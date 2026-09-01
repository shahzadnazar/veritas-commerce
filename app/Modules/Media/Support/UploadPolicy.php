<?php

declare(strict_types=1);

namespace App\Modules\Media\Support;

use App\Modules\Media\Exceptions\RejectedUpload;
use Illuminate\Http\UploadedFile;

/**
 * What the store will accept, decided from the bytes.
 *
 * Three things are never trusted: the extension, the browser's claimed
 * MIME type, and the filename. An attacker controls all three. What is
 * checked instead is what the file actually contains.
 */
final class UploadPolicy
{
    /**
     * @param  array<int, string>  $acceptedMimes
     */
    public function __construct(
        private readonly array $acceptedMimes,
        private readonly int $maxBytes,
        private readonly bool $requireImage,
    ) {}

    public static function forImages(): self
    {
        return new self(
            acceptedMimes: array_values((array) config('veritas.media.accepted_mimes')),
            maxBytes: ((int) config('veritas.media.max_upload_kb')) * 1024,
            requireImage: true,
        );
    }

    public static function forDocuments(): self
    {
        return new self(
            acceptedMimes: array_values((array) config('veritas.storage.document_mimes')),
            maxBytes: ((int) config('veritas.storage.max_document_kb')) * 1024,
            requireImage: false,
        );
    }

    /**
     * @return array{mime: string, bytes: int, width: int|null, height: int|null}
     *
     * @throws RejectedUpload
     */
    public function inspect(UploadedFile $file): array
    {
        if (! $file->isValid()) {
            throw new RejectedUpload('That upload did not complete. Try again.');
        }

        $path = $file->getRealPath();

        if ($path === false || ! is_readable($path)) {
            throw new RejectedUpload('That file could not be read.');
        }

        $bytes = (int) filesize($path);

        if ($bytes <= 0) {
            throw new RejectedUpload('That file is empty.');
        }

        if ($bytes > $this->maxBytes) {
            $limit = (int) round($this->maxBytes / 1024 / 1024, 0);

            throw new RejectedUpload("That file is larger than the {$limit}MB limit.");
        }

        // The bytes, not the browser. A shell script named logo.jpg and
        // announced as image/jpeg is caught here and nowhere else.
        $mime = @mime_content_type($path);

        if ($mime === false) {
            throw new RejectedUpload('That file could not be identified.');
        }

        if (! in_array($mime, $this->acceptedMimes, true)) {
            throw new RejectedUpload("Files of type {$mime} are not accepted here.");
        }

        $width = null;
        $height = null;

        if ($this->requireImage) {
            $size = @getimagesize($path);

            if ($size === false) {
                // The type said image, the decoder disagrees: a truncated
                // or deliberately malformed file.
                throw new RejectedUpload('That image could not be read. It may be damaged.');
            }

            $width = $size[0];
            $height = $size[1];

            if ($width < 1 || $height < 1) {
                throw new RejectedUpload('That image has no dimensions.');
            }
        }

        return ['mime' => $mime, 'bytes' => $bytes, 'width' => $width, 'height' => $height];
    }

    public function extensionFor(string $mime): string
    {
        return match ($mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/jpeg' => 'jpg',
            'application/pdf' => 'pdf',
            default => 'bin',
        };
    }
}
