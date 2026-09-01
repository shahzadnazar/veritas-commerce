<?php

declare(strict_types=1);

namespace App\Modules\Media\Enums;

/**
 * Who may read an object.
 *
 * Not a flag on a file but a choice of where it is stored: public objects
 * live on a disk fronted by a CDN, private ones on a disk with no public
 * route at all. Getting this wrong by forgetting an ACL is a class of
 * mistake the two-disk split removes.
 */
enum Visibility: string
{
    /** Product imagery, store logos — meant to be hotlinked and cached. */
    case Public = 'public';

    /** Registration documents, tax paperwork — reachable only after a check. */
    case Private = 'private';

    public function disk(): string
    {
        return match ($this) {
            self::Public => (string) config('veritas.storage.public_disk'),
            self::Private => (string) config('veritas.storage.private_disk'),
        };
    }
}
