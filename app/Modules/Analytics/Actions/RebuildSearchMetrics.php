<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Actions;

use App\Modules\Analytics\Support\AnalyticsDay;
use App\Modules\Analytics\Support\SearchPhrase;
use App\Modules\Events\Enums\InteractionEventType;
use Illuminate\Support\Facades\DB;

/**
 * One row per search phrase per day.
 *
 * The phrase is normalised — trimmed, lowercased, inner whitespace
 * collapsed — because "Kettle", "kettle " and "kettle  " are one question
 * a shopper asked, and three rows nobody can compare is the failure mode
 * of every search report that skips this step.
 *
 * The row a catalogue team actually acts on is `zero_result_searches`: a
 * phrase people keep typing that finds nothing is either a product the
 * marketplace should stock or a synonym the index should know, and both
 * are decisions somebody can take on Monday morning.
 *
 * `clicks`, `cart_adds` and `purchases` are attributed to the phrase that
 * preceded them *in the same session on the same day* — the honest limit
 * of what the event log can support without a durable identifier.
 */
final class RebuildSearchMetrics
{
    /** How many phrases a single day may record. */
    private const MAX_PHRASES = 5_000;

    /** @return int rows written */
    public function __invoke(AnalyticsDay $day): int
    {
        $phrases = $this->searches($day);
        $this->attributeDownstream($day, $phrases);

        $rows = [];

        foreach ($phrases as $phrase => $counts) {
            $rows[] = [
                'day' => $day->date,
                'query_normalised' => (string) $phrase,
                'searches' => $counts['searches'] ?? 0,
                'sessions' => $counts['sessions'] ?? 0,
                'zero_result_searches' => $counts['zero_result_searches'] ?? 0,
                'clicks' => $counts['clicks'] ?? 0,
                'cart_adds' => $counts['cart_adds'] ?? 0,
                'purchases' => $counts['purchases'] ?? 0,
                'computed_at' => now(),
            ];
        }

        DB::transaction(function () use ($day, $rows): void {
            DB::table('daily_search_metrics')->where('day', $day->date)->delete();

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('daily_search_metrics')->insert($chunk);
            }
        });

        return count($rows);
    }

    /**
     * Every phrase searched on this day, with its counts.
     *
     * @return array<string, array<string, int>>
     */
    private function searches(AnalyticsDay $day): array
    {
        $rows = DB::table('interaction_events')
            ->where('event_type', InteractionEventType::SearchPerformed->value)
            ->whereNotNull('search_query')
            ->where('created_at', '>=', $day->startsAt)
            ->where('created_at', '<', $day->endsAt)
            ->select([
                'search_query',
                'anonymous_session_id',
                'user_id',
                DB::raw("case when (metadata ->> 'zero_results') = 'true' then 1 else 0 end as empty_handed"),
            ])
            ->get();

        /** @var array<string, array<string, int>> $phrases */
        $phrases = [];
        /** @var array<string, array<string, true>> $sessions */
        $sessions = [];

        foreach ($rows as $row) {
            $phrase = SearchPhrase::normalise((string) $row->search_query);

            if ($phrase === null) {
                continue;
            }

            $phrases[$phrase]['searches'] = ($phrases[$phrase]['searches'] ?? 0) + 1;
            $phrases[$phrase]['zero_result_searches'] =
                ($phrases[$phrase]['zero_result_searches'] ?? 0) + (int) $row->empty_handed;

            $visitor = $this->visitorKey($row->user_id, $row->anonymous_session_id);

            if ($visitor !== null) {
                $sessions[$phrase][$visitor] = true;
            }
        }

        foreach ($sessions as $phrase => $visitors) {
            $phrases[$phrase]['sessions'] = count($visitors);
        }

        // A day with thousands of distinct phrases is a crawler or an
        // attack; keeping the busiest is more useful than storing the
        // long tail of one-off typos, and bounds the table.
        uasort($phrases, static fn (array $left, array $right): int => ($right['searches'] ?? 0) <=> ($left['searches'] ?? 0));

        return array_slice($phrases, 0, self::MAX_PHRASES, preserve_keys: true);
    }

    /**
     * Clicks, cart adds and purchases, credited to the phrase that led to
     * them.
     *
     * "Led to" means: the visitor searched that phrase earlier the same
     * day, and this is the most recent phrase they searched before the
     * event. Last-touch, stated plainly, because every other attribution
     * model needs data the marketplace does not have — and a report whose
     * model is unstated is a report two readers disagree about.
     *
     * @param  array<string, array<string, int>>  $phrases
     */
    private function attributeDownstream(AnalyticsDay $day, array &$phrases): void
    {
        $timeline = DB::table('interaction_events')
            ->whereIn('event_type', [
                InteractionEventType::SearchPerformed->value,
                InteractionEventType::SearchResultClicked->value,
                InteractionEventType::CartItemAdded->value,
                InteractionEventType::PurchaseCompleted->value,
            ])
            ->where('created_at', '>=', $day->startsAt)
            ->where('created_at', '<', $day->endsAt)
            ->orderBy('created_at')
            ->orderBy('id')
            ->select(['event_type', 'search_query', 'user_id', 'anonymous_session_id'])
            ->get();

        $columns = [
            InteractionEventType::SearchResultClicked->value => 'clicks',
            InteractionEventType::CartItemAdded->value => 'cart_adds',
            InteractionEventType::PurchaseCompleted->value => 'purchases',
        ];

        /** @var array<string, string> $lastPhrase */
        $lastPhrase = [];

        foreach ($timeline as $row) {
            $visitor = $this->visitorKey($row->user_id, $row->anonymous_session_id);

            if ($visitor === null) {
                continue;
            }

            $type = (string) $row->event_type;

            if ($type === InteractionEventType::SearchPerformed->value) {
                $phrase = SearchPhrase::normalise((string) ($row->search_query ?? ''));

                if ($phrase !== null) {
                    $lastPhrase[$visitor] = $phrase;
                }

                continue;
            }

            $phrase = $lastPhrase[$visitor] ?? null;

            // No preceding search: the visitor arrived some other way, and
            // crediting a phrase they never typed would be an invention.
            if ($phrase === null || ! isset($phrases[$phrase])) {
                continue;
            }

            $column = $columns[$type] ?? null;

            if ($column !== null) {
                $phrases[$phrase][$column] = ($phrases[$phrase][$column] ?? 0) + 1;
            }
        }
    }

    private function visitorKey(mixed $userId, mixed $sessionId): ?string
    {
        if ($userId !== null) {
            return 'u'.(int) $userId;
        }

        return is_string($sessionId) && $sessionId !== '' ? 's'.$sessionId : null;
    }
}
