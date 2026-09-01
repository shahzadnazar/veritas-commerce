<?php

declare(strict_types=1);

namespace App\Modules\Media\Data;

use App\Modules\Media\Enums\Visibility;

/**
 * A reference to something in the object store.
 *
 * Disk and key travel together because neither is meaningful alone: the
 * same key on the public and the private disk are different objects, and a
 * record that stores only the key cannot be moved between them safely.
 */
final readonly class StoredObject
{
    public function __construct(
        public string $disk,
        public string $key,
        public string $mime,
        public int $bytes,
        public Visibility $visibility,
        public ?string $checksum = null,
        public ?int $width = null,
        public ?int $height = null,
    ) {}

    /** The single-column form: `disk:key`, parseable and greppable. */
    public function reference(): string
    {
        return $this->disk.':'.$this->key;
    }

    /** @return array{disk: string, key: string, mime: string, bytes: int, visibility: string, checksum: string|null, width: int|null, height: int|null} */
    public function toArray(): array
    {
        return [
            'disk' => $this->disk,
            'key' => $this->key,
            'mime' => $this->mime,
            'bytes' => $this->bytes,
            'visibility' => $this->visibility->value,
            'checksum' => $this->checksum,
            'width' => $this->width,
            'height' => $this->height,
        ];
    }
}
