<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Http\Controllers;

use App\Modules\Analytics\Enums\AnalyticsPeriod;
use App\Modules\Analytics\Queries\GetSellerAnalytics;
use App\Modules\Payouts\Support\PayoutPolicy;
use App\Modules\Sellers\Concerns\CurrentSeller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One seller's own performance.
 *
 * The seller is whoever the membership resolves to. Nothing here takes a
 * seller id from the request, so there is no seller id to tamper with
 * (§27, §52) — and GetSellerAnalytics scopes every query by that id in its
 * WHERE clause rather than filtering afterwards, so there is no code path
 * that reads a rival's row and then decides not to show it.
 *
 * Read-only. There is one route, it is a GET, and the controller has no
 * other method — §2 written into the surface rather than left to the
 * controller body to honour.
 */
final class SellerAnalyticsController
{
    public function __construct(private readonly GetSellerAnalytics $analytics) {}

    public function index(Request $request): Response
    {
        $membership = CurrentSeller::membership();
        $seller = $membership?->sellerAccount;

        abort_if($seller === null, 404);

        $filters = $request->validate([
            'period' => ['nullable', 'string', 'max:32'],
        ]);

        $period = AnalyticsPeriod::fromRequest($filters['period'] ?? null);

        return Inertia::render('Analytics/Index', [
            'analytics' => ($this->analytics)(
                (int) $seller->id,
                $period,
                PayoutPolicy::currency(),
            ),
            'filters' => ['period' => $period->value],
        ]);
    }
}
