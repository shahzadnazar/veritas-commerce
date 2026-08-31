<?php

declare(strict_types=1);

namespace App\Modules\Media\Contracts;

use Illuminate\Http\UploadedFile;

/**
 * The storage port.
 *
 * Store logic depends on this, never on a provider SDK: the architecture
 * targets Cloudflare R2, development uses a local disk, and neither is
 * named anywhere outside the binding.
 */
interface MediaStore
{
    /** @return array{disk: string, path: string, mime: string, bytes: int} */
    public function put(UploadedFile $file, string $collection): array;

    public function url(string $disk, string $path): string;

    public function delete(string $disk, string $path): void;
}
