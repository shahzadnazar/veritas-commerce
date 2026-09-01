<?php

declare(strict_types=1);

namespace App\Modules\Cart\Enums;

use App\Support\HasStatusTone;
use App\Support\StatusTone;

/**
 * Where a cart stands.
 *
 * Carts are kept rather than deleted. A converted cart is the evidence
 * behind an order, and an abandoned one is the most useful thing the
 * marketplace knows about what a customer wanted and did not buy.
 */
enum CartStatus: string implements HasStatusTone
{
    /** The live cart. At most one per customer and per browser. */
    case Active = 'active';

    /** Became an order. Kept, never reused. */
    case Converted = 'converted';

    /** Folded into another cart when its owner signed in. */
    case Merged = 'merged';

    /** Aged out. */
    case Abandoned = 'abandoned';

    public function isLive(): bool
    {
        return $this === self::Active;
    }

    public function tone(): StatusTone
    {
        return match ($this) {
            self::Active => StatusTone::Pending,
            self::Converted => StatusTone::Neutral,
            self::Merged, self::Abandoned => StatusTone::Inactive,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Converted => 'Converted',
            self::Merged => 'Merged',
            self::Abandoned => 'Abandoned',
        };
    }
}
