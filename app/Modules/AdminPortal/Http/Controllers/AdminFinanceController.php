<?php

declare(strict_types=1);

namespace App\Modules\AdminPortal\Http\Controllers;

use App\Modules\Payouts\Queries\GetSellerFinancialPosition;
use App\Modules\Payouts\Queries\SummarisePlatformFinance;
use App\Modules\Payouts\Support\PayoutPolicy;
use App\Modules\Sellers\Models\SellerAccount;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The platform's finance dashboard. §39 and §45.
 *
 * Every figure comes from SummarisePlatformFinance, which reads immutable
 * records and names its own definitions. Nothing is computed here, and in
 * particular nothing is computed from the CURRENT commission rate — a rate
 * change must not move last month's number.
 *
 * DATES (§70) are read as platform-timezone days and converted to UTC
 * before they reach the query, so two admins in different countries asking
 * for "March" get the same March. The browser's timezone is never
 * consulted.
 */
final class AdminFinanceController
{
    public function __construct(
        private readonly SummarisePlatformFinance $summary,
        private readonly GetSellerFinancialPosition $position,
    ) {}

    public function index(Request $request): Response
    {
        $timezone = (string) config('app.timezone');
        $currency = strtoupper($request->string('currency')->toString() ?: PayoutPolicy::currency());

        $from = $this->boundary($request->string('from')->toString(), $timezone, startOfDay: true);
        $to = $this->boundary($request->string('to')->toString(), $timezone, startOfDay: false);

        return Inertia::render('Finance/Index', [
            'summary' => ($this->summary)($from, $to, $currency),
            'negativeSellers' => $this->negativeSellers($currency),
            'filters' => [
                'from' => $request->string('from')->toString(),
                'to' => $request->string('to')->toString(),
                'currency' => $currency,
                'timezone' => $timezone,
            ],
            'currencies' => PayoutPolicy::supportedCurrencies(),
        ]);
    }

    /**
     * Sellers whose net position is below zero. §45.
     *
     * One grouped query for the whole list. This is operational
     * information, not a collections process — the platform does not chase
     * these balances, it waits for the seller's next earnings to offset
     * them, which is what §43 describes.
     *
     * @return array<int, array<string, mixed>>
     */
    private function negativeSellers(string $currency): array
    {
        $negative = DB::table('seller_ledger_entries')
            ->where('currency', $currency)
            ->whereIn('status', ['pending', 'clearing', 'available', 'paid'])
            ->groupBy('seller_account_id')
            ->havingRaw('sum(amount_minor) < 0')
            ->selectRaw('seller_account_id, sum(amount_minor) as net')
            ->limit(100)
            ->get();

        if ($negative->isEmpty()) {
            return [];
        }

        $ids = $negative->map(static fn (object $row): int => (int) $row->seller_account_id)->all();
        $positions = ($this->position)->forSellers($ids, $currency);
        $names = SellerAccount::query()->whereIn('id', $ids)->pluck('legal_name', 'id');

        return $negative->map(static function (object $row) use ($positions, $names, $currency): array {
            $id = (int) $row->seller_account_id;
            $position = $positions[$id] ?? null;

            return [
                'sellerAccountId' => $id,
                'sellerName' => (string) ($names[$id] ?? 'Unknown store'),
                'netMinor' => (int) $row->net,
                'net' => Money::formatSigned((int) $row->net, $currency),
                // What is coming that will offset it, which is the only
                // number anyone looking at this list actually wants.
                'incomingMinor' => $position === null ? 0 : $position->pendingMinor + $position->clearingMinor,
                'incoming' => Money::formatSigned(
                    $position === null ? 0 : $position->pendingMinor + $position->clearingMinor,
                    $currency,
                ),
            ];
        })->all();
    }

    private function boundary(string $value, string $timezone, bool $startOfDay): ?Carbon
    {
        if ($value === '') {
            return null;
        }

        $date = Carbon::parse($value, $timezone);

        return ($startOfDay ? $date->startOfDay() : $date->endOfDay())->utc();
    }
}
