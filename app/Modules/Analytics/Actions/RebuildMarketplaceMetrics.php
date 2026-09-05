<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Actions;

use App\Modules\Analytics\Support\AnalyticsDay;
use App\Modules\Events\Enums\InteractionEventType;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Enums\RefundStatus;
use Illuminate\Support\Facades\DB;

/**
 * One row per day per currency: how the marketplace did.
 *
 * The behavioural columns are counted from interaction events. The money
 * columns are **copied from M7's definitions**, not recomputed:
 *
 *   - GMV is the sum of captured payments, keyed on `captured_at` — a
 *     payment created in March and captured in April is April's money,
 *     which is what SummarisePlatformFinance says and therefore what this
 *     says.
 *   - Refunds are successful refunds, keyed on `succeeded_at`.
 *   - Commission comes from platform_revenue_entries, which are immutable
 *     snapshots. Never from the current commission rate: a rate change
 *     must not move a past day's figure.
 *
 * §48, and it is the entire reason those three read from the payment and
 * ledger tables rather than from `purchase_completed` events: an event is
 * emitted by a listener that can fail, be replayed, or be emitted twice,
 * and a GMV built from clickstream is a number that disagrees with the
 * ledger. The ledger is right.
 */
final class RebuildMarketplaceMetrics
{
    /** @return int rows written */
    public function __invoke(AnalyticsDay $day): int
    {
        $rows = [];

        foreach ($this->currencies($day) as $currency) {
            $rows[] = array_merge(
                ['day' => $day->date, 'currency' => $currency, 'computed_at' => now()],
                $this->behaviour($day),
                $this->commerce($day, $currency),
            );
        }

        DB::transaction(function () use ($day, $rows): void {
            DB::table('daily_marketplace_metrics')->where('day', $day->date)->delete();

            if ($rows !== []) {
                DB::table('daily_marketplace_metrics')->insert($rows);
            }
        });

        return count($rows);
    }

    /**
     * Which currencies this day needs a row for.
     *
     * §71: currency is a filter, never a sum across. A day with payments
     * in two currencies gets two rows, and nothing anywhere adds them
     * together. The platform default is always present, so a day with no
     * trade still has a row saying so rather than a gap a chart has to
     * guess at.
     *
     * @return array<int, string>
     */
    private function currencies(AnalyticsDay $day): array
    {
        $default = strtoupper((string) config('veritas.money.default_currency'));

        $traded = DB::table('payments')
            ->whereNotNull('captured_at')
            ->where('captured_at', '>=', $day->startsAt)
            ->where('captured_at', '<', $day->endsAt)
            ->distinct()
            ->pluck('currency')
            ->map(static fn (mixed $currency): string => strtoupper((string) $currency))
            ->all();

        return array_values(array_unique([$default, ...$traded]));
    }

    /**
     * Behaviour, from the event log. Currency-independent, so the same
     * counts appear on each currency row — a view is a view whatever the
     * shopper eventually paid in.
     *
     * @return array<string, int>
     */
    private function behaviour(AnalyticsDay $day): array
    {
        $counts = DB::table('interaction_events')
            ->where('created_at', '>=', $day->startsAt)
            ->where('created_at', '<', $day->endsAt)
            ->groupBy('event_type')
            ->selectRaw('event_type, count(*) as total')
            ->pluck('total', 'event_type')
            ->map(static fn (mixed $total): int => (int) $total)
            ->all();

        $of = static fn (InteractionEventType $type): int => (int) ($counts[$type->value] ?? 0);

        return [
            'product_views' => $of(InteractionEventType::ProductViewed),
            'unique_visitors' => $this->uniqueVisitors($day),
            'searches' => $of(InteractionEventType::SearchPerformed),
            'zero_result_searches' => $this->zeroResultSearches($day),
            'search_clicks' => $of(InteractionEventType::SearchResultClicked),
            'cart_adds' => $of(InteractionEventType::CartItemAdded),
            'checkouts_started' => $of(InteractionEventType::CheckoutStarted),
            'wishlist_adds' => $of(InteractionEventType::WishlistItemAdded),
            'recommendation_impressions' => $of(InteractionEventType::RecommendationShown),
            'recommendation_clicks' => $of(InteractionEventType::RecommendationClicked),
        ];
    }

    /**
     * Distinct visitors, counted once whether signed in or not.
     *
     * A signed-in visitor is their account; anybody else is their rotating
     * pseudonymous session. Neither is a durable fingerprint, which is the
     * arrangement M0 chose and this does not quietly improve on.
     */
    private function uniqueVisitors(AnalyticsDay $day): int
    {
        return (int) DB::table('interaction_events')
            ->where('created_at', '>=', $day->startsAt)
            ->where('created_at', '<', $day->endsAt)
            ->distinct()
            ->count(DB::raw("coalesce('u'||user_id::text, 's'||anonymous_session_id)"));
    }

    /**
     * Searches that found nothing.
     *
     * Read from the flag the search recorded at the time, because
     * re-running the query now would answer "does it find nothing today",
     * which is a different question and usually a different answer. The
     * same `zero_results` key the admin search-health page has read since
     * M3 — one flag, two readers.
     */
    private function zeroResultSearches(AnalyticsDay $day): int
    {
        return (int) DB::table('interaction_events')
            ->where('event_type', InteractionEventType::SearchPerformed->value)
            ->where('created_at', '>=', $day->startsAt)
            ->where('created_at', '<', $day->endsAt)
            ->whereRaw("(metadata ->> 'zero_results') = 'true'")
            ->count();
    }

    /**
     * Orders, customers and money — from the transactional tables only.
     *
     * @return array<string, int>
     */
    private function commerce(AnalyticsDay $day, string $currency): array
    {
        $paidOrders = DB::table('payments')
            ->where('currency', $currency)
            ->whereIn('status', [PaymentStatus::Captured->value, PaymentStatus::PartiallyRefunded->value])
            ->whereNotNull('captured_at')
            ->where('captured_at', '>=', $day->startsAt)
            ->where('captured_at', '<', $day->endsAt);

        return [
            'paid_orders' => (int) $paidOrders->clone()->distinct()->count('marketplace_order_id'),
            'new_customers' => $this->newCustomers($day, $currency),
            'gmv_minor' => (int) DB::table('payments')
                ->where('currency', $currency)
                ->whereNotNull('captured_at')
                ->where('captured_at', '>=', $day->startsAt)
                ->where('captured_at', '<', $day->endsAt)
                ->sum('amount_minor'),
            'refunds_minor' => (int) DB::table('refunds')
                ->where('currency', $currency)
                ->where('status', RefundStatus::Succeeded->value)
                ->whereNotNull('succeeded_at')
                ->where('succeeded_at', '>=', $day->startsAt)
                ->where('succeeded_at', '<', $day->endsAt)
                ->sum('amount_minor'),
            'commission_minor' => (int) DB::table('platform_revenue_entries')
                ->where('currency', $currency)
                ->where('created_at', '>=', $day->startsAt)
                ->where('created_at', '<', $day->endsAt)
                ->sum('amount_minor'),
        ];
    }

    /**
     * Customers whose first paid order landed on this day.
     *
     * "First" means first ever, not first in the window, so a customer who
     * bought in January is not counted as new in March. Anonymous orders
     * carry no account and are excluded rather than guessed at from an
     * email — two orders from one address is not proof of one person.
     */
    private function newCustomers(AnalyticsDay $day, string $currency): int
    {
        $paidToday = DB::table('payments as p')
            ->join('marketplace_orders as mo', 'mo.id', '=', 'p.marketplace_order_id')
            ->where('p.currency', $currency)
            ->whereNotNull('p.captured_at')
            ->where('p.captured_at', '>=', $day->startsAt)
            ->where('p.captured_at', '<', $day->endsAt)
            ->whereNotNull('mo.user_id')
            ->distinct()
            ->pluck('mo.user_id')
            ->map(intval(...))
            ->all();

        if ($paidToday === []) {
            return 0;
        }

        $earlier = DB::table('payments as p')
            ->join('marketplace_orders as mo', 'mo.id', '=', 'p.marketplace_order_id')
            ->whereNotNull('p.captured_at')
            ->where('p.captured_at', '<', $day->startsAt)
            ->whereIn('mo.user_id', $paidToday)
            ->distinct()
            ->pluck('mo.user_id')
            ->map(intval(...))
            ->all();

        return count(array_diff($paidToday, $earlier));
    }
}
