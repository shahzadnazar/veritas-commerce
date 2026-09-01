<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Data;

use App\Modules\Inventory\Enums\StockState;

/**
 * One offer's stock, as a value.
 *
 * The authoritative representation §3 asks for: every surface that needs to
 * know what is left takes one of these, so `available` is subtraction done
 * once rather than a formula copied into a React component, a Blade view
 * and three queries.
 *
 * Constructed from the database's own generated `available` column wherever
 * a row is at hand, so the value object and the schema cannot disagree.
 */
final readonly class StockLevel
{
    private function __construct(
        public int $onHand,
        public int $reserved,
        public int $available,
        public int $threshold,
        public StockState $state,
    ) {}

    public static function of(int $onHand, int $reserved, int $threshold): self
    {
        $available = $onHand - $reserved;

        return new self(
            onHand: $onHand,
            reserved: $reserved,
            available: $available,
            threshold: $threshold,
            state: StockState::forAvailability($available, $threshold),
        );
    }

    /** Nothing stocked at all — a product with no balance row anywhere. */
    public static function none(): self
    {
        return self::of(0, 0, 0);
    }

    public function canFulfil(int $quantity): bool
    {
        return $quantity > 0 && $this->available >= $quantity;
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return [
            'onHand' => $this->onHand,
            'reserved' => $this->reserved,
            'available' => $this->available,
            'threshold' => $this->threshold,
            'state' => $this->state->value,
            'stateLabel' => $this->state->label(),
        ];
    }
}
