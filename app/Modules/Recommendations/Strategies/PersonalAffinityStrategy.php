<?php

declare(strict_types=1);

namespace App\Modules\Recommendations\Strategies;

use App\Modules\Events\Enums\InteractionEventType;
use App\Modules\Recommendations\Contracts\RecommendationStrategy;
use App\Modules\Recommendations\Data\RecommendationRequest;
use App\Modules\Recommendations\Queries\GetPopularProducts;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * "Recommended for you", built the boring way.
 *
 * §44 rules out learned models, so this is a two-step rule instead: work
 * out which categories this visitor keeps returning to, then show what is
 * popular in them. It is not clever, but it is explainable — every product
 * on the shelf can be traced to a category the visitor actually engaged
 * with — and explainable beats clever when a shopper asks why they are
 * being shown something.
 *
 * The affinity weights come from InteractionEventType, which has carried
 * them since M0, rather than from a second table of numbers here.
 */
final class PersonalAffinityStrategy implements RecommendationStrategy
{
    /** How many categories a visitor's taste is summarised as. */
    private const CATEGORY_DEPTH = 5;

    /** How far back a visitor's behaviour still says something. */
    private const LOOKBACK_DAYS = 90;

    public function __construct(private readonly GetPopularProducts $popular) {}

    public function key(): string
    {
        return 'personal_affinity';
    }

    public function supports(RecommendationRequest $request): bool
    {
        return $request->hasVisitor();
    }

    /** @return array<int, int> */
    public function candidates(RecommendationRequest $request): array
    {
        $categoryIds = $this->favouriteCategories($request);

        if ($categoryIds === []) {
            return [];
        }

        return ($this->popular)(
            GetPopularProducts::defaultWindow(),
            $request->candidateLimit(),
            $categoryIds,
        );
    }

    /**
     * The categories this visitor engaged with most, weighted by intent.
     *
     * The database counts; PHP weights. Keeping the weighting out of SQL
     * is what lets InteractionEventType stay the single definition of what
     * an event is worth — a CASE expression built from the enum would be
     * the same numbers written twice, and the copy in SQL is the one that
     * would go stale.
     *
     * The grouped result is small by construction: one visitor's events,
     * collapsed to (category, event type) pairs.
     *
     * @return array<int, int>
     */
    private function favouriteCategories(RecommendationRequest $request): array
    {
        if (! $request->hasVisitor()) {
            return [];
        }

        $rows = DB::table('interaction_events as e')
            ->join('product_search_documents as d', 'd.product_id', '=', 'e.product_id')
            ->whereNotNull('d.category_id')
            ->where('e.created_at', '>=', now()->subDays(self::LOOKBACK_DAYS))
            ->where(function (Builder $query) use ($request): void {
                if ($request->userId !== null) {
                    $query->where('e.user_id', $request->userId);

                    return;
                }

                $query->where('e.anonymous_session_id', $request->anonymousSessionId);
            })
            ->groupBy('d.category_id', 'e.event_type')
            ->select([
                'd.category_id',
                'e.event_type',
                DB::raw('count(*) as occurrences'),
            ])
            ->get();

        /** @var array<int, int> $affinity */
        $affinity = [];

        foreach ($rows as $row) {
            $type = InteractionEventType::tryFrom((string) $row->event_type);

            if ($type === null) {
                continue;
            }

            $weight = $type->affinityWeight();

            if ($weight <= 0) {
                // Zero-weight events are operational, and a negative one
                // is a removal — neither belongs in a measure of what
                // somebody is drawn to.
                continue;
            }

            $categoryId = (int) $row->category_id;
            $affinity[$categoryId] = ($affinity[$categoryId] ?? 0) + ($weight * (int) $row->occurrences);
        }

        if ($affinity === []) {
            return [];
        }

        // Score descending, then category id, so a visitor with two
        // equally-weighted interests gets the same shelf on every refresh.
        uksort($affinity, static function (int $left, int $right) use ($affinity): int {
            return [$affinity[$right], $left] <=> [$affinity[$left], $right];
        });

        return array_slice(array_keys($affinity), 0, self::CATEGORY_DEPTH);
    }
}
