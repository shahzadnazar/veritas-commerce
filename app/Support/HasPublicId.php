<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Every externally visible record carries a ULID public_id.
 *
 * Internal keys stay bigint for join performance; the ULID is what appears
 * in URLs and API payloads so sequential ids never leak volume or allow
 * enumeration of another seller's records.
 */
trait HasPublicId
{
    public static function bootHasPublicId(): void
    {
        static::creating(function (Model $model): void {
            if (blank($model->getAttribute('public_id'))) {
                $model->setAttribute('public_id', (string) Str::ulid());
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
