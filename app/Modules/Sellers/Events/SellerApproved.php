<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Raised once a seller account exists and its owner can sign in.
 *
 * Carries ids rather than models so a queued listener cannot resurrect a
 * stale copy of a record that has moved on since the event was dispatched.
 */
final class SellerApproved
{
    use Dispatchable;

    public function __construct(
        public readonly int $sellerAccountId,
        public readonly int $applicationId,
        public readonly int $ownerUserId,
    ) {}
}
