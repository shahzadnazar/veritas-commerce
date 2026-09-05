<?php

declare(strict_types=1);

namespace App\Modules\Recommendations\Enums;

/**
 * The two kinds of co-occurrence the marketplace mines.
 *
 * Both are pair counts over a window, and they differ only in what
 * produced the pair — a browsing session or a paid order. That difference
 * is the whole reason they are separate rows rather than one blended
 * score: "people who viewed this also viewed" and "bought together" answer
 * different questions, and averaging them answers neither.
 */
enum AssociationKind: string
{
    case ViewedTogether = 'viewed_together';
    case BoughtTogether = 'bought_together';

    /**
     * How many distinct sessions or orders make a pair evidence.
     *
     * §37 and §38: one shared session is a coincidence. Below this the
     * strategy contributes nothing and the fallback chain takes over,
     * rather than a recommendation being invented from a single visit.
     * Never below 2 — a threshold of one is no threshold.
     */
    public function minimumSupport(): int
    {
        $configured = config('veritas.recommendations.minimum_support.'.$this->value);

        return max(2, is_numeric($configured) ? (int) $configured : 2);
    }

    public function label(): string
    {
        return match ($this) {
            self::ViewedTogether => 'Viewed together',
            self::BoughtTogether => 'Bought together',
        };
    }
}
