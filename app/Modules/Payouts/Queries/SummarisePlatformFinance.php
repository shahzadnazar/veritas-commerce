<?php

declare(strict_types=1);

namespace App\Modules\Payouts\Queries;

use App\Modules\Ledger\Enums\LedgerEntryStatus;
use App\Modules\Payouts\Enums\PayoutAllocationStatus;
use App\Modules\Payouts\Enums\PayoutStatus;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The platform's own finance figures, from immutable records only. §37–§39.
 *
 * THE DEFINITIONS, stated once here and used with these meanings
 * everywhere. "Revenue" is not one of them, deliberately: it means
 * whichever of the first four the reader assumed, and a dashboard that
 * uses it is a dashboard two people will read differently.
 *
 *   GMV                Gross value of successfully captured marketplace
 *                      payments, BEFORE refunds. What customers paid.
 *
 *   NET SALES          GMV less successful refunds. What the marketplace
 *                      kept after returns.
 *
 *   PLATFORM COMMISSION  Commission recognised from the immutable order
 *                      snapshots, less commission reversals. NOT the
 *                      current commission rate applied to anything — a
 *                      rate change must never move a past month's figure.
 *
 *   SELLER EARNINGS    What sellers were credited, less refund reversals:
 *                      the sum of the seller ledger, whatever state it is
 *                      in.
 *
 *   SELLER PAYOUTS     Amounts actually settled to sellers. Not approved,
 *                      not requested — settled.
 *
 *   SELLER LIABILITY   What the platform still owes sellers: the net of
 *                      the seller ledger. Pending, clearing and available
 *                      money less what has been paid out.
 *
 * Currency is a filter, never a sum across (§71). Every figure returned is
 * in the one currency asked for, and the caller is told which.
 *
 * Dates are interpreted in the platform timezone and stored in UTC (§70),
 * so a report for "March" is the platform's March rather than whichever
 * one the reader's browser was in.
 */
final class SummarisePlatformFinance
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(?Carbon $from = null, ?Carbon $to = null, string $currency = 'USD'): array
    {
        $currency = strtoupper($currency);

        $window = static function (mixed $query, string $column) use ($from, $to): mixed {
            if ($from !== null) {
                $query->where($column, '>=', $from);
            }

            if ($to !== null) {
                $query->where($column, '<=', $to);
            }

            return $query;
        };

        // GMV: captured payments. `captured_at` rather than `created_at`,
        // because a payment created in March and captured in April is
        // April's money.
        $gmv = (int) $window(
            DB::table('payments')
                ->where('currency', $currency)
                ->whereNotNull('captured_at'),
            'captured_at',
        )->sum('amount_minor');

        $refunds = (int) $window(
            DB::table('refunds')
                ->where('currency', $currency)
                ->where('status', 'succeeded')
                ->whereNotNull('succeeded_at'),
            'succeeded_at',
        )->sum('amount_minor');

        // Commission from the snapshots, plus its own reversals — which
        // are stored negative, so this is a sum and not a subtraction.
        $commission = (int) $window(
            DB::table('platform_revenue_entries')->where('currency', $currency),
            'created_at',
        )->sum('amount_minor');

        $earnings = (int) $window(
            DB::table('seller_ledger_entries')
                ->where('currency', $currency)
                ->whereIn('type', ['sale_earning', 'refund_reversal', 'adjustment']),
            'created_at',
        )->sum('amount_minor');

        $paidOut = -(int) $window(
            DB::table('seller_ledger_entries')
                ->where('currency', $currency)
                ->where('type', 'payout'),
            'created_at',
        )->sum('amount_minor');

        /*
         * Liability and the money held against it are BALANCES, not flows,
         * so they are not windowed. "What the platform owes sellers as of
         * March" is not a question this data can answer honestly — the
         * ledger records when each entry was written, not what the balance
         * was on a date — and pretending otherwise would put a confident
         * wrong number on a finance screen.
         */
        $buckets = DB::table('seller_ledger_entries')
            ->where('currency', $currency)
            ->groupBy('status')
            ->selectRaw('status, sum(amount_minor) as total')
            ->pluck('total', 'status')
            ->map(static fn (mixed $total): int => (int) $total)
            ->all();

        $of = static fn (LedgerEntryStatus $status): int => (int) ($buckets[$status->value] ?? 0);

        $liability = $of(LedgerEntryStatus::Pending)
            + $of(LedgerEntryStatus::Clearing)
            + $of(LedgerEntryStatus::Available)
            + $of(LedgerEntryStatus::Paid);

        $reserved = (int) DB::table('payout_allocations')
            ->where('currency', $currency)
            ->where('status', PayoutAllocationStatus::Held->value)
            ->sum('amount_minor');

        $openPayouts = (int) DB::table('payout_requests')
            ->where('currency', $currency)
            ->whereIn('status', PayoutStatus::openValues())
            ->sum('amount_minor');

        $format = static fn (int $minor): string => Money::formatSigned($minor, $currency);

        return [
            'currency' => $currency,
            'from' => $from?->toIso8601String(),
            'to' => $to?->toIso8601String(),
            'flows' => [
                'gmvMinor' => $gmv,
                'gmv' => $format($gmv),
                'refundsMinor' => $refunds,
                'refunds' => $format($refunds),
                'netSalesMinor' => $gmv - $refunds,
                'netSales' => $format($gmv - $refunds),
                'commissionMinor' => $commission,
                'commission' => $format($commission),
                'sellerEarningsMinor' => $earnings,
                'sellerEarnings' => $format($earnings),
                'payoutsPaidMinor' => $paidOut,
                'payoutsPaid' => $format($paidOut),
            ],
            'balances' => [
                'pendingMinor' => $of(LedgerEntryStatus::Pending),
                'pending' => $format($of(LedgerEntryStatus::Pending)),
                'clearingMinor' => $of(LedgerEntryStatus::Clearing),
                'clearing' => $format($of(LedgerEntryStatus::Clearing)),
                'availableMinor' => $of(LedgerEntryStatus::Available) + $of(LedgerEntryStatus::Paid),
                'available' => $format($of(LedgerEntryStatus::Available) + $of(LedgerEntryStatus::Paid)),
                'reservedMinor' => $reserved,
                'reserved' => $format($reserved),
                'openPayoutsMinor' => $openPayouts,
                'openPayouts' => $format($openPayouts),
                'liabilityMinor' => $liability,
                'liability' => $format($liability),
            ],
        ];
    }
}
