<?php

declare(strict_types=1);

namespace App\Modules\Payments\Data;

final readonly class CaptureResult
{
    public function __construct(
        public bool $succeeded,
        public ?string $chargeId = null,
        public ?string $failureCode = null,
        public ?string $failureMessage = null,
        /** @var array<string, mixed> */
        public array $raw = [],
    ) {}
}
