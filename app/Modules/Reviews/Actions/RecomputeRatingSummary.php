<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Actions;

use App\Modules\Reviews\Enums\ReviewStatus;
use App\Modules\Reviews\Models\ProductRatingSummary;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds one product's rating from its reviews.
 *
 * RECOMPUTED, NEVER INCREMENTED. A counter nudged by +1 on publish and -1
 * on hide is one missed call away from a rating nobody can explain, and
 * the miss is silent. This reads the reviews and writes the answer, so the
 * summary is a cache of a query rather than a second record of the truth —
 * which is also what makes `reviews:reconcile-ratings` able to check it.
 *
 * Called inside the transaction that changed a review (§16), so the visible
 * rating and the JSON-LD can never disagree because one was updated by a
 * job that had not run yet. It is cheap: one grouped query over the
 * reviews of a single product.
 *
 * Only PUBLISHED reviews count. Hidden, rejected and withdrawn ones are
 * excluded by the same predicate the public list uses, which is why §3
 * holds the moment a moderator acts.
 */
final class RecomputeRatingSummary
{
    public function __invoke(int $productId): ProductRatingSummary
    {
        /** @var array<int, int> $byRating */
        $byRating = DB::table('product_reviews')
            ->where('product_id', $productId)
            ->where('status', ReviewStatus::Published->value)
            ->groupBy('rating')
            ->selectRaw('rating, count(*) as total')
            ->pluck('total', 'rating')
            ->map(static fn (mixed $total): int => (int) $total)
            ->all();

        $verified = (int) DB::table('product_reviews')
            ->where('product_id', $productId)
            ->where('status', ReviewStatus::Published->value)
            ->where('verified_purchase', true)
            ->count();

        $count = 0;
        $sum = 0;

        foreach ($byRating as $rating => $total) {
            $count += $total;
            $sum += (int) $rating * $total;
        }

        /*
         * No reviews means no average — null rather than 0.00.
         *
         * §69 depends on this being null and not zero: a product with
         * nothing said about it must emit no aggregateRating at all, and
         * a 0.00 average would be both a lie and outside the 1–5 scale.
         */
        $average = $count === 0
            ? null
            : number_format($sum / $count, 2, '.', '');

        /** @var ProductRatingSummary $summary */
        $summary = ProductRatingSummary::query()->updateOrCreate(
            ['product_id' => $productId],
            [
                'published_review_count' => $count,
                'verified_review_count' => $verified,
                'rating_sum' => $sum,
                'rating_average' => $average,
                'count_1' => $byRating[1] ?? 0,
                'count_2' => $byRating[2] ?? 0,
                'count_3' => $byRating[3] ?? 0,
                'count_4' => $byRating[4] ?? 0,
                'count_5' => $byRating[5] ?? 0,
                'recomputed_at' => now(),
            ],
        );

        return $summary;
    }
}
