<?php

declare(strict_types=1);

namespace App\Modules\Recommendations\Strategies;

use App\Modules\Recommendations\Contracts\RecommendationStrategy;
use App\Modules\Recommendations\Data\RecommendationRequest;
use Illuminate\Support\Facades\DB;

/**
 * The last strategy in every chain: what was published most recently.
 *
 * It needs no behaviour, no associations and no history, so it is the one
 * strategy that always has an answer on a marketplace that opened
 * yesterday. §39: the chain ends here rather than in an empty shelf.
 *
 * Not a good recommendation — it knows nothing about the visitor — but a
 * newly listed product is at least a real, buyable, publicly visible one,
 * which is more than an empty carousel offers.
 */
final class NewArrivalsStrategy implements RecommendationStrategy
{
    public function key(): string
    {
        return 'new_arrivals';
    }

    public function supports(RecommendationRequest $request): bool
    {
        return true;
    }

    /** @return array<int, int> */
    public function candidates(RecommendationRequest $request): array
    {
        return DB::table('product_search_documents')
            ->where('is_public', true)
            ->where('offer_count', '>', 0)
            ->orderByDesc('in_stock')
            ->orderByRaw('published_at desc nulls last')
            ->orderByDesc('product_id')
            ->limit($request->candidateLimit())
            ->pluck('product_id')
            ->map(intval(...))
            ->all();
    }
}
