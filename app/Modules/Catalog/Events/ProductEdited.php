<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A canonical product's own identity changed — its title, category, brand
 * or one of its trade identifiers.
 *
 * Carries ids rather than models, so a queued listener cannot resurrect a
 * stale copy of a record that has moved on since the event was raised.
 */
final class ProductEdited
{
    use Dispatchable;

    public function __construct(
        public readonly int $productId,
        public readonly string $actorType,
        public readonly ?int $actorId = null,
        /** @var array<int, string> the columns that actually changed */
        public readonly array $changedColumns = [],
    ) {}
}
