<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Actions;

use App\Modules\Analytics\Support\AnalyticsDay;

/**
 * All four projections, for one day.
 *
 * The unit of rebuild is a day because that is the unit of the key: each
 * projection deletes exactly that day's rows and reinserts them, so a
 * rebuild of the 3rd cannot disturb the 2nd, a failed run leaves a day
 * stale rather than half-written, and running the same day twice produces
 * identical rows (§60).
 *
 * Nothing here is incremental. An incremental rollup is faster and wrong
 * the first time an event arrives late — and analytics that quietly
 * disagree with the source are worse than analytics that take a minute
 * longer to compute.
 */
final class RebuildDailyMetrics
{
    public function __construct(
        private readonly RebuildMarketplaceMetrics $marketplace,
        private readonly RebuildProductMetrics $products,
        private readonly RebuildSellerMetrics $sellers,
        private readonly RebuildSearchMetrics $searches,
    ) {}

    /** @return array<string, int> rows written, per projection */
    public function __invoke(AnalyticsDay $day): array
    {
        return [
            'marketplace' => ($this->marketplace)($day),
            'products' => ($this->products)($day),
            'sellers' => ($this->sellers)($day),
            'searches' => ($this->searches)($day),
        ];
    }

    /**
     * @param  array<int, AnalyticsDay>  $days
     * @return array<string, int>
     */
    public function forDays(array $days): array
    {
        $totals = ['marketplace' => 0, 'products' => 0, 'sellers' => 0, 'searches' => 0];

        foreach ($days as $day) {
            foreach ($this($day) as $projection => $written) {
                $totals[$projection] += $written;
            }
        }

        return $totals;
    }
}
