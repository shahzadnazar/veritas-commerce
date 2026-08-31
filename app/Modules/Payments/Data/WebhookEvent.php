<?php

declare(strict_types=1);

namespace App\Modules\Payments\Data;

final readonly class WebhookEvent
{
    public function __construct(
        public string $provider,
        public string $eventId,
        public string $type,
        /** @var array<string, mixed> */
        public array $payload,
    ) {}
}
