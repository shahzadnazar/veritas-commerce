<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Queries;

use App\Modules\Reviews\Actions\RecomputeRatingSummary;
use App\Modules\Reviews\Enums\ReviewStatus;
use Illuminate\Support\Facades\DB;

/**
 * Checks every rating summary against the reviews underneath it. §17.
 *
 * The summary is a cache of a query, so the check is simply that query,
 * run again and compared. Four ways a row can be wrong, and all four are
 * looked for:
 *
 *   1. The published count disagrees with the reviews.
 *   2. The sum disagrees.
 *   3. The average disagrees with sum / count — a row where those two
 *      contradict each other is corrupt on its own terms.
 *   4. A product has published reviews and no summary row at all, or a
 *      summary row and no reviews.
 *
 * UNLIKE THE FINANCE RECONCILIATION, THIS ONE MAY REPAIR. The difference
 * is what the data is: a seller ledger discrepancy is evidence of
 * something that went wrong with money and must be preserved for a person
 * to look at, whereas a rating summary is derived and its only correct
 * value is the one recomputation produces. Repair is opt-in
 * (`--repair`), reports every row it touched, and never alters a review.
 */
final class ReconcileRatings
{
    public function __construct(private readonly RecomputeRatingSummary $recompute) {}

    /**
     * @return array<int, array{product_id: int, check: string, detail: string}>
     */
    public function __invoke(bool $repair = false): array
    {
        $expected = $this->expectedByProduct();
        $stored = $this->storedByProduct();

        $problems = [];

        foreach ($expected as $productId => $want) {
            $have = $stored[$productId] ?? null;

            if ($have === null) {
                $problems[] = [
                    'product_id' => $productId,
                    'check' => 'summary_missing',
                    'detail' => sprintf('%d published reviews and no summary row', $want['count']),
                ];

                continue;
            }

            if ($have['count'] !== $want['count'] || $have['sum'] !== $want['sum']) {
                $problems[] = [
                    'product_id' => $productId,
                    'check' => 'summary_disagrees_with_reviews',
                    'detail' => sprintf(
                        'summary says %d reviews totalling %d, the reviews say %d totalling %d',
                        $have['count'], $have['sum'], $want['count'], $want['sum'],
                    ),
                ];

                continue;
            }

            // The average against its own sum and count. A row that
            // contradicts itself is corrupt however it got that way.
            $average = $want['count'] === 0 ? null : number_format($want['sum'] / $want['count'], 2, '.', '');

            if ($have['average'] !== $average) {
                $problems[] = [
                    'product_id' => $productId,
                    'check' => 'average_disagrees_with_its_own_sum',
                    'detail' => sprintf(
                        'summary says %s, %d / %d is %s',
                        $have['average'] ?? 'null', $want['sum'], $want['count'], $average ?? 'null',
                    ),
                ];
            }
        }

        // A summary for a product whose reviews have all gone.
        foreach ($stored as $productId => $have) {
            if (! isset($expected[$productId]) && $have['count'] !== 0) {
                $problems[] = [
                    'product_id' => $productId,
                    'check' => 'summary_counts_reviews_that_are_not_public',
                    'detail' => sprintf('summary says %d reviews, none are published', $have['count']),
                ];
            }
        }

        if ($repair) {
            foreach (array_unique(array_column($problems, 'product_id')) as $productId) {
                ($this->recompute)((int) $productId);
            }
        }

        return $problems;
    }

    /**
     * What the reviews say, per product.
     *
     * @return array<int, array{count: int, sum: int}>
     */
    private function expectedByProduct(): array
    {
        $rows = DB::table('product_reviews')
            ->where('status', ReviewStatus::Published->value)
            ->groupBy('product_id')
            ->selectRaw('product_id, count(*) as total, sum(rating) as rating_sum')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row->product_id] = [
                'count' => (int) $row->total,
                'sum' => (int) $row->rating_sum,
            ];
        }

        return $out;
    }

    /**
     * What the summaries say.
     *
     * @return array<int, array{count: int, sum: int, average: string|null}>
     */
    private function storedByProduct(): array
    {
        $rows = DB::table('product_rating_summaries')
            ->select(['product_id', 'published_review_count', 'rating_sum', 'rating_average'])
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row->product_id] = [
                'count' => (int) $row->published_review_count,
                'sum' => (int) $row->rating_sum,
                'average' => $row->rating_average === null ? null : (string) $row->rating_average,
            ];
        }

        return $out;
    }
}
