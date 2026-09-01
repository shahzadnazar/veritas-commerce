<?php

declare(strict_types=1);

namespace App\Modules\Offers\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Carries ids, never models: a queued listener must read current state. */
final class OfferSuspended
{
    use Dispatchable;

    public function __construct(
        public readonly int $offerId,
        public readonly ?int $actorId = null,
        public readonly ?string $reason = null,
    ) {}
}
