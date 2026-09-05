<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Queries;

use App\Modules\Ledger\Enums\LedgerEntryStatus;
use App\Modules\Ledger\Models\SellerLedgerEntry;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * What a seller has, derived from the ledger and from nothing else.
 *
 * §68. Not "sum the delivered orders" and not "sum seller_earning_total on
 * the seller orders": those are summaries of intent, and they drift the
 * moment a refund is issued. The ledger is the financial truth, so the
 * balance is a filtered sum of it — positive earnings and the negative
 * reversals that cancel them, netted within each state.
 *
 * That netting is the point of §34 and §36. A seller with a $90 earning
 * clearing and a $20 reversal against it has $70 clearing, not $90; a
 * seller with $90 available and a $20 reversal has $70 available. The
 * original entry is never touched and the reversal is never merged into
 * it — history stays exactly as it happened, and the arithmetic is done
 * when the question is asked.
 *
 * Currency-aware throughout: Phase 1 runs on one currency operationally,
 * but a balance that added two currencies together would be wrong in a way
 * nobody notices until it matters.
 */
final class SellerBalance
{
    /**
     * @return array{pending: Money, clearing: Money, available: Money, reserved: Money, paid: Money, currency: string}
     */
    public function __invoke(int $sellerAccountId, string $currency = 'USD'): array
    {
        $rows = DB::table('seller_ledger_entries')
            ->where('seller_account_id', $sellerAccountId)
            ->where('currency', $currency)
            ->groupBy('status')
            ->selectRaw('status, sum(amount_minor) as total')
            ->pluck('total', 'status')
            ->map(static fn (mixed $total): int => (int) $total)
            ->all();

        $of = static fn (LedgerEntryStatus $status): Money => Money::of(
            // A net position cannot go below zero in Phase 1 — no payout
            // has left, so every reversal is covered — but the arithmetic
            // is done before the clamp so a negative one is visible in
            // tests rather than silently absorbed.
            max(0, (int) ($rows[$status->value] ?? 0)),
            $currency,
        );

        return [
            'pending' => $of(LedgerEntryStatus::Pending),
            'clearing' => $of(LedgerEntryStatus::Clearing),
            'available' => $of(LedgerEntryStatus::Available),
            'reserved' => $of(LedgerEntryStatus::ReservedForPayout),
            'paid' => $of(LedgerEntryStatus::Paid),
            'currency' => $currency,
        ];
    }

    /**
     * The signed net for one state, before any clamp.
     *
     * Used by tests and by anything that has to know a seller is actually
     * negative rather than merely at zero — which M7 will, when a payout
     * has already left and a refund lands behind it.
     */
    public function netMinor(int $sellerAccountId, LedgerEntryStatus $status, string $currency = 'USD'): int
    {
        return (int) SellerLedgerEntry::query()
            ->withoutGlobalScopes()
            ->where('seller_account_id', $sellerAccountId)
            ->where('currency', $currency)
            ->where('status', $status->value)
            ->sum('amount_minor');
    }
}
