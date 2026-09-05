<?php

declare(strict_types=1);

namespace App\Modules\Recommendations\Actions;

use App\Modules\Recommendations\Enums\PopularitySignal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes product_popularity_scores from behaviour.
 *
 * Every window is rebuilt from scratch rather than incremented, which is
 * what makes the command idempotent (§60): running it twice on unchanged
 * data produces byte-identical rows, and a run that died halfway leaves a
 * window that is merely stale, never half-counted.
 *
 * §48, and it is the whole reason this class only ever touches one table:
 * popularity is a behavioural number. It is computed from interaction
 * events and wishlist saves, it influences nothing but the order products
 * appear in, and it is never — in this class or any other — allowed to
 * become an input to an order total, a commission, a ledger entry or a
 * payout. The purchase *count* here is a count of events, not of money.
 */
final class RebuildPopularityScores
{
    /**
     * @return int rows written
     */
    public function __invoke(int $windowDays, ?Carbon $asOf = null): int
    {
        $asOf ??= Carbon::now();
        $since = $asOf->copy()->subDays($windowDays);

        $counts = $this->countEvents($since, $asOf);
        $this->mergeWishlistSaves($counts, $since, $asOf);

        $rows = [];

        foreach ($counts as $productId => $signals) {
            $score = 0;

            foreach (PopularitySignal::cases() as $signal) {
                $score += ($signals[$signal->value] ?? 0) * $signal->weight();
            }

            $rows[] = [
                'product_id' => $productId,
                'window_days' => $windowDays,
                'score' => max(0, $score),
                'view_count' => $signals[PopularitySignal::View->value] ?? 0,
                'search_click_count' => $signals[PopularitySignal::SearchClick->value] ?? 0,
                'wishlist_count' => $signals[PopularitySignal::Wishlist->value] ?? 0,
                'cart_count' => $signals[PopularitySignal::Cart->value] ?? 0,
                'purchase_count' => $signals[PopularitySignal::Purchase->value] ?? 0,
                'computed_at' => $asOf,
            ];
        }

        DB::transaction(function () use ($rows, $windowDays): void {
            // Delete-then-insert, inside one transaction, so a reader
            // never sees a window that is half-old and half-new. The
            // window is the unit of replacement because it is the unit a
            // caller asks for.
            DB::table('product_popularity_scores')->where('window_days', $windowDays)->delete();

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('product_popularity_scores')->insert($chunk);
            }
        });

        return count($rows);
    }

    /**
     * Event counts per product, per signal, within the window.
     *
     * Counts distinct visitors rather than raw events: one person
     * refreshing a page forty times is one person interested, and a
     * popularity score that rewards refreshing is a score that rewards
     * whoever has the most patience.
     *
     * @return array<int, array<string, int>>
     */
    private function countEvents(Carbon $since, Carbon $asOf): array
    {
        $eventToSignal = [];

        foreach (PopularitySignal::cases() as $signal) {
            foreach ($signal->eventValues() as $value) {
                $eventToSignal[$value] = $signal->value;
            }
        }

        if ($eventToSignal === []) {
            return [];
        }

        $rows = DB::table('interaction_events')
            ->select([
                'product_id',
                'event_type',
                DB::raw('count(distinct coalesce(user_id::text, anonymous_session_id, event_id)) as visitors'),
            ])
            ->whereNotNull('product_id')
            ->whereIn('event_type', array_keys($eventToSignal))
            ->where('created_at', '>=', $since)
            ->where('created_at', '<=', $asOf)
            ->groupBy('product_id', 'event_type')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $signal = $eventToSignal[(string) $row->event_type] ?? null;

            if ($signal === null) {
                continue;
            }

            $productId = (int) $row->product_id;
            $counts[$productId][$signal] = ($counts[$productId][$signal] ?? 0) + (int) $row->visitors;
        }

        return $counts;
    }

    /**
     * Wishlist saves, which are rows rather than events.
     *
     * Saving something is a durable statement of intent — stronger than a
     * view, weaker than a purchase — and it lives in wishlist_items, so it
     * is counted from there rather than from a duplicate event nobody
     * would remember to emit.
     *
     * @param  array<int, array<string, int>>  $counts
     */
    private function mergeWishlistSaves(array &$counts, Carbon $since, Carbon $asOf): void
    {
        $rows = DB::table('wishlist_items')
            ->select(['product_id', DB::raw('count(distinct user_id) as savers')])
            ->where('created_at', '>=', $since)
            ->where('created_at', '<=', $asOf)
            ->groupBy('product_id')
            ->get();

        foreach ($rows as $row) {
            $productId = (int) $row->product_id;
            $counts[$productId][PopularitySignal::Wishlist->value] =
                ($counts[$productId][PopularitySignal::Wishlist->value] ?? 0) + (int) $row->savers;
        }
    }
}
