<?php

declare(strict_types=1);

namespace App\Modules\Cart\Data;

use App\Modules\Cart\Enums\CartIssueCode;

/**
 * Something the customer needs to know about one line.
 *
 * Structured rather than a string, so the frontend can render it, the
 * checkout can decide whether it blocks, and a test can assert on it —
 * three things a formatted sentence cannot support.
 */
final readonly class CartIssue
{
    public function __construct(
        public CartIssueCode $code,
        /** Which line, by its identity. Null for a whole-cart issue. */
        public ?string $lineIdentity = null,
        public ?string $detail = null,
        /** For a quantity reduction: what is actually available. */
        public ?int $available = null,
        /** For a price change: what it was and what it is now, in minor units. */
        public ?int $previousMinor = null,
        public ?int $currentMinor = null,
    ) {}

    public function isBlocking(): bool
    {
        return $this->code->isBlocking();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'code' => $this->code->value,
            'label' => $this->code->label(),
            'lineIdentity' => $this->lineIdentity,
            'detail' => $this->detail,
            'available' => $this->available,
            'previousMinor' => $this->previousMinor,
            'currentMinor' => $this->currentMinor,
            'blocking' => $this->isBlocking(),
        ], static fn (mixed $value): bool => $value !== null);
    }
}
