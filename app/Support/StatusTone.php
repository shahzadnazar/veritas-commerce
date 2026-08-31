<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The four semantic tones a status may render as.
 *
 * The system is mono: status is carried by fill weight and label, never by
 * hue. Phase 6 consistency review, finding 1 — every badge in all three areas
 * reads its tone from one mapping. The TypeScript side is generated from this
 * enum, and StatusPresentationTest asserts the two agree.
 */
enum StatusTone: string
{
    case Neutral = 'neutral';
    case Pending = 'pending';
    case Critical = 'critical';
    case Inactive = 'inactive';
}
