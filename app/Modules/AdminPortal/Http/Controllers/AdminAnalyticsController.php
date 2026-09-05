<?php

declare(strict_types=1);

namespace App\Modules\AdminPortal\Http\Controllers;

use App\Modules\Analytics\Enums\AnalyticsPeriod;
use App\Modules\Analytics\Queries\GetMarketplaceAnalytics;
use App\Modules\Analytics\Queries\GetProductAnalytics;
use App\Modules\Analytics\Queries\GetSearchAnalytics;
use App\Modules\Identity\Enums\AdminPermission;
use App\Modules\Payouts\Support\PayoutPolicy;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The platform's marketplace analytics.
 *
 * Read-only, all the way down. Every figure comes from a daily projection
 * that `analytics:rebuild` computed, so this page cannot aggregate the
 * event log at request time (§29) and cannot alter anything it reads (§2).
 * There is no write action on this controller at all — not a filter that
 * persists, not a preference, nothing.
 *
 * §71: currency is a filter, never a sum across. The money figures are in
 * the one currency asked for and the page says which.
 *
 * The per-seller breakdown sits behind its own permission, because a
 * seller-by-seller performance table is the one report here that would be
 * worth something to a competitor.
 */
final class AdminAnalyticsController
{
    private const SELLER_ROWS = 20;

    public function __construct(
        private readonly GetMarketplaceAnalytics $marketplace,
        private readonly GetProductAnalytics $products,
        private readonly GetSearchAnalytics $searches,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'period' => ['nullable', 'string', 'max:32'],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);

        $period = AnalyticsPeriod::fromRequest($filters['period'] ?? null);
        $currency = strtoupper((string) ($filters['currency'] ?? PayoutPolicy::currency()));

        $admin = $request->user('admin');
        $showsSellers = $admin !== null && $admin->can(AdminPermission::ViewSellerAnalytics);

        return Inertia::render('Analytics/Index', [
            'marketplace' => ($this->marketplace)($period, $currency),
            'products' => ($this->products)($period, $currency),
            'search' => ($this->searches)($period),
            // Absent, not empty, when the permission is missing: a table
            // rendered with no rows reads as "no sellers traded", which is
            // a different and wrong statement.
            'sellers' => $showsSellers ? $this->sellers($period, $currency) : null,
            'currencies' => PayoutPolicy::supportedCurrencies(),
            'filters' => ['period' => $period->value, 'currency' => $currency],
        ]);
    }

    /**
     * The busiest sellers over the window, from the seller projection.
     *
     * @return array<int, array<string, mixed>>
     */
    private function sellers(AnalyticsPeriod $period, string $currency): array
    {
        $dates = array_map(
            static fn ($day): string => $day->date,
            $period->dayRange(),
        );

        if ($dates === []) {
            return [];
        }

        $rows = DB::table('daily_seller_metrics as m')
            ->join('seller_accounts as s', 's.id', '=', 'm.seller_account_id')
            ->whereIn('m.day', $dates)
            ->groupBy('m.seller_account_id', 's.public_id', 's.legal_name')
            ->selectRaw(
                'm.seller_account_id, s.public_id, s.legal_name, '.
                'sum(m.orders) as orders, sum(m.units_sold) as units, '.
                'sum(m.gross_minor) as gross, sum(m.refunds_minor) as refunds, '.
                'sum(m.earnings_minor) as earnings, sum(m.delivered_orders) as delivered'
            )
            ->orderByRaw('sum(m.gross_minor) desc')
            ->orderBy('m.seller_account_id')
            ->limit(self::SELLER_ROWS)
            ->get();

        return $rows->map(static function ($row) use ($currency): array {
            $orders = (int) $row->orders;

            return [
                'publicId' => (string) $row->public_id,
                'name' => (string) $row->legal_name,
                'orders' => $orders,
                'units' => (int) $row->units,
                'delivered' => (int) $row->delivered,
                'grossMinor' => (int) $row->gross,
                'gross' => Money::of((int) $row->gross, $currency)->format(),
                'refundsMinor' => (int) $row->refunds,
                'refunds' => Money::of((int) $row->refunds, $currency)->format(),
                'earningsMinor' => (int) $row->earnings,
                'earnings' => Money::formatSigned((int) $row->earnings, $currency),
                // Null rather than 0% when nothing was ordered: a seller
                // with no orders has no delivery rate to report.
                'deliveryRate' => $orders === 0
                    ? null
                    : round((int) $row->delivered / $orders * 100, 1),
            ];
        })->all();
    }
}
