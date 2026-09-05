<?php

declare(strict_types=1);

namespace App\Modules\Recommendations\Strategies;

use App\Modules\Events\Enums\InteractionEventType;
use App\Modules\Recommendations\Contracts\RecommendationStrategy;
use App\Modules\Recommendations\Data\RecommendationRequest;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What this visitor looked at, most recent first.
 *
 * The only strategy that reads the raw event log at request time, and it
 * does so because there is nothing to precompute: a projection of "what
 * this person just viewed" would be stale the moment they viewed the next
 * thing. The read is bounded — one visitor, one event type, a small
 * limit — and hits the index on (user_id, created_at).
 *
 * §2: read-only. Interaction events are the analytics module's input, not
 * anybody's source of financial or transactional truth, and this query
 * takes exactly one column out of them.
 */
final class RecentlyViewedStrategy implements RecommendationStrategy
{
    public function key(): string
    {
        return 'recently_viewed';
    }

    public function supports(RecommendationRequest $request): bool
    {
        return $request->hasVisitor();
    }

    /** @return array<int, int> */
    public function candidates(RecommendationRequest $request): array
    {
        if (! $request->hasVisitor()) {
            return [];
        }

        $rows = DB::table('interaction_events')
            ->where('event_type', InteractionEventType::ProductViewed->value)
            ->whereNotNull('product_id')
            ->where(function (Builder $query) use ($request): void {
                // A signed-in visitor is identified by their account, and
                // only falls back to the session when there is no account:
                // a rotating anonymous id is a weaker claim on the same
                // history, not an additional one.
                if ($request->userId !== null) {
                    $query->where('user_id', $request->userId);

                    return;
                }

                $query->where('anonymous_session_id', $request->anonymousSessionId);
            })
            ->groupBy('product_id')
            ->orderByRaw('max(created_at) desc')
            ->orderBy('product_id')
            ->limit($request->candidateLimit())
            ->pluck('product_id');

        return $rows->map(intval(...))->all();
    }
}
