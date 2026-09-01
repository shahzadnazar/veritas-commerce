<?php

declare(strict_types=1);

namespace App\Modules\AdminPortal\Queries;

use App\Modules\Events\Enums\InteractionEventType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Basic search health, read from the event stream.
 *
 * Deliberately four numbers and two lists. §36 warns against building an
 * analytics warehouse in M3, and the four things that actually change what
 * a catalogue team does that week are: what people search for, what they
 * search for and find nothing, how much searching is happening at all, and
 * whether the results get clicked.
 *
 * Everything comes from interaction_events. No separate aggregate table,
 * no scheduled rollup — at this catalogue's scale the raw events answer
 * these in milliseconds, and inventing a pipeline now would be a pipeline
 * to maintain before there is anything to put through it.
 */
final class SearchHealth
{
    /** @return array<string, mixed> */
    public function __invoke(int $days = 30): array
    {
        $since = Carbon::now()->subDays($days);

        $searches = (int) DB::table('interaction_events')
            ->where('event_type', InteractionEventType::SearchPerformed->value)
            ->where('created_at', '>=', $since)
            ->count();

        $zeroResults = (int) DB::table('interaction_events')
            ->where('event_type', InteractionEventType::SearchPerformed->value)
            ->where('created_at', '>=', $since)
            ->whereRaw("(metadata ->> 'zero_results') = 'true'")
            ->count();

        $clicks = (int) DB::table('interaction_events')
            ->where('event_type', InteractionEventType::SearchResultClicked->value)
            ->where('created_at', '>=', $since)
            ->count();

        return [
            'days' => $days,
            'searches' => $searches,
            'zeroResults' => $zeroResults,
            'clicks' => $clicks,
            // Clicks per search rather than a funnel: with one event type
            // on each side, anything more elaborate would be a rate
            // computed from numbers that do not pair up.
            'clickRate' => $searches === 0 ? null : round($clicks / $searches * 100, 1),
            'topSearches' => $this->topSearches($since),
            'zeroResultSearches' => $this->zeroResultSearches($since),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function topSearches(Carbon $since): array
    {
        /** @var array<int, stdClass> $rows */
        $rows = DB::table('interaction_events')
            ->select('search_query', DB::raw('count(*) as total'))
            ->where('event_type', InteractionEventType::SearchPerformed->value)
            ->where('created_at', '>=', $since)
            ->whereNotNull('search_query')
            ->groupBy('search_query')
            ->orderByDesc('total')
            ->limit(20)
            ->get()
            ->all();

        return array_map(
            static fn (stdClass $row): array => [
                'query' => (string) $row->search_query,
                'count' => (int) $row->total,
            ],
            $rows,
        );
    }

    /**
     * The most valuable list on the page.
     *
     * A search people repeat that returns nothing is either a product the
     * catalogue should carry or a word the index should understand — both
     * are actionable, which is more than most metrics manage.
     *
     * @return array<int, array<string, mixed>>
     */
    private function zeroResultSearches(Carbon $since): array
    {
        /** @var array<int, stdClass> $rows */
        $rows = DB::table('interaction_events')
            ->select('search_query', DB::raw('count(*) as total'))
            ->where('event_type', InteractionEventType::SearchPerformed->value)
            ->where('created_at', '>=', $since)
            ->whereNotNull('search_query')
            ->whereRaw("(metadata ->> 'zero_results') = 'true'")
            ->groupBy('search_query')
            ->orderByDesc('total')
            ->limit(20)
            ->get()
            ->all();

        return array_map(
            static fn (stdClass $row): array => [
                'query' => (string) $row->search_query,
                'count' => (int) $row->total,
            ],
            $rows,
        );
    }
}
