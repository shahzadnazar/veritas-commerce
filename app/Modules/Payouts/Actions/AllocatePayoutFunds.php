<?php

declare(strict_types=1);

namespace App\Modules\Payouts\Actions;

use App\Modules\Ledger\Enums\LedgerEntryStatus;
use App\Modules\Payouts\Enums\PayoutAllocationStatus;
use App\Modules\Payouts\Exceptions\PayoutNotPermitted;
use App\Modules\Payouts\Models\PayoutAllocation;
use App\Modules\Payouts\Models\PayoutRequest;
use Illuminate\Support\Facades\DB;

/**
 * Decides WHICH earnings pay for a payout, and holds them.
 *
 * §6. A single "reserved: $600" total answers how much is held but not
 * where it came from, and every question finance actually asks later —
 * which orders funded this withdrawal, what happens to it when one of them
 * is refunded, does the debit match what was held — needs the second
 * answer. So the hold is a set of rows pointing at specific ledger
 * entries.
 *
 * Oldest earnings first. Not because the money is distinguishable — it is
 * not — but because a deterministic rule makes the allocation of a given
 * request reproducible, and because the oldest earning is the one least
 * likely to be refunded, which keeps the common case tidy.
 *
 * MUST be called inside the caller's transaction, with the seller's
 * financial scope already locked. It does not lock anything itself: the
 * balance it allocates against was read under that lock, and taking a
 * second, narrower lock here would open exactly the window the caller
 * closed.
 */
final class AllocatePayoutFunds
{
    /**
     * @param  int  $amountMinor  positive, already validated against withdrawable
     * @return array<int, PayoutAllocation>
     */
    public function __invoke(PayoutRequest $request, int $amountMinor): array
    {
        /*
         * The pool: positive entries that have finished clearing, less
         * whatever earlier payouts already took from each of them.
         *
         * Only positive entries are allocable — a refund reversal is not
         * money to pay out, it is money owed back, and it has already
         * reduced the withdrawable figure this amount was checked against.
         * The negatives are therefore accounted for once, globally, rather
         * than twice.
         */
        $rows = DB::table('seller_ledger_entries as e')
            ->leftJoin('payout_allocations as a', function ($join): void {
                $join->on('a.seller_ledger_entry_id', '=', 'e.id')
                    ->whereIn('a.status', [
                        PayoutAllocationStatus::Held->value,
                        PayoutAllocationStatus::Settled->value,
                    ]);
            })
            ->where('e.seller_account_id', $request->seller_account_id)
            ->where('e.currency', $request->currency)
            ->where('e.status', LedgerEntryStatus::Available->value)
            ->where('e.amount_minor', '>', 0)
            ->groupBy('e.id', 'e.amount_minor', 'e.created_at')
            ->orderBy('e.created_at')
            ->orderBy('e.id')
            ->selectRaw('e.id, e.amount_minor, coalesce(sum(a.amount_minor), 0) as taken')
            ->get();

        $remaining = $amountMinor;
        $allocations = [];
        $now = now();

        foreach ($rows as $row) {
            if ($remaining <= 0) {
                break;
            }

            $capacity = (int) $row->amount_minor - (int) $row->taken;

            if ($capacity <= 0) {
                continue;
            }

            $take = min($capacity, $remaining);
            $remaining -= $take;

            $allocations[] = PayoutAllocation::query()->create([
                'payout_request_id' => $request->id,
                'seller_ledger_entry_id' => (int) $row->id,
                'seller_account_id' => $request->seller_account_id,
                'currency' => $request->currency,
                'amount_minor' => $take,
                'status' => PayoutAllocationStatus::Held->value,
                'created_at' => $now,
            ]);
        }

        /*
         * Cannot normally happen: withdrawable is capped by the available
         * bucket, and the unallocated capacity of positive entries is
         * always at least that. If it ever does, the request is refused
         * rather than partly held — a payout backed by less money than it
         * promises is the failure mode this whole file exists to prevent.
         */
        if ($remaining > 0) {
            throw PayoutNotPermitted::insufficientAllocatableFunds(
                $amountMinor,
                $amountMinor - $remaining,
                $request->currency,
            );
        }

        return $allocations;
    }
}
