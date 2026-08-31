<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Exceptions;

use RuntimeException;

final class InsufficientStock extends RuntimeException
{
    public function __construct(
        public readonly int $offerId,
        public readonly int $requested,
        public readonly int $available,
    ) {
        parent::__construct(
            "Offer {$offerId} has {$available} available but {$requested} were requested."
        );
    }
}
