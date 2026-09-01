<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Carries ids rather than models, so a queued listener cannot resurrect a
 * stale copy of a record that has moved on since the event was raised.
 */
final class ProductSuspended
{
    use Dispatchable;

    public function __construct(
        public readonly int $productId,
        public readonly ?int $actorId = null,
        public readonly ?string $reason = null,
    ) {}
}
