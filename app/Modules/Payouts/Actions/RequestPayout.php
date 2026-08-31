<?php

declare(strict_types=1);

namespace App\Modules\Payouts\Actions;

use App\Modules\Ledger\Actions\PostLedgerEntry;
use App\Modules\Ledger\Enums\LedgerEntryType;
use App\Modules\Ledger\Queries\GetSellerBalance;
use App\Modules\Orders\Actions\AllocateReference;
use App\Modules\Payouts\Enums\PayoutStatus;
use App\Modules\Payouts\Exceptions\PayoutNotPermitted;
use App\Modules\Payouts\Models\PayoutRequest;
use App\Modules\Sellers\Concerns\CurrentSeller;
use App\Modules\Sellers\Models\SellerAccount;
use Illuminate\Support\Facades\DB;

/**
 * A seller asks to withdraw part of their available balance.
 *
 * The amount is validated against the ledger-derived balance, never a
 * cached column, and the requested amount is immediately reserved so it
 * cannot be spent twice. The database's partial unique index is what
 * actually enforces "one open request at a time" — this check is the
 * friendly error, not the control.
 */
final class RequestPayout
{
    public function __construct(
        private readonly GetSellerBalance $getBalance,
        private readonly PostLedgerEntry $postEntry,
        private readonly AllocateReference $references,
    ) {}

    public function __invoke(
        SellerAccount $seller,
        int $amountMinor,
        ?int $bankAccountId = null,
        string $currency = 'USD',
    ): PayoutRequest {
        if (! $seller->status->canRequestPayout()) {
            throw PayoutNotPermitted::sellerNotEligible($seller->status);
        }

        $minimum = (int) config('veritas.payouts.minimum_minor');

        if ($amountMinor < $minimum) {
            throw PayoutNotPermitted::belowMinimum($amountMinor, $minimum);
        }

        return CurrentSeller::actingAs($seller->id, fn (): PayoutRequest => DB::transaction(function () use ($seller, $amountMinor, $bankAccountId, $currency): PayoutRequest {
            $open = PayoutRequest::query()
                ->where('seller_account_id', $seller->id)
                ->whereIn('status', ['requested', 'under_review', 'approved', 'processing'])
                ->exists();

            if ($open) {
                throw PayoutNotPermitted::alreadyOpen();
            }

            $balance = ($this->getBalance)($seller->id, $currency);

            if (! $balance->canRequest($amountMinor)) {
                throw PayoutNotPermitted::exceedsAvailable($amountMinor, $balance->available->minor);
            }

            $request = PayoutRequest::create([
                'reference' => $this->references->payoutReference(),
                'seller_account_id' => $seller->id,
                'seller_bank_account_id' => $bankAccountId,
                'currency' => $currency,
                'amount_minor' => $amountMinor,
                'status' => PayoutStatus::Requested->value,
                'requested_at' => now(),
            ]);

            // Hold the money so it leaves the available balance immediately.
            ($this->postEntry)(
                seller: $seller,
                type: LedgerEntryType::PayoutReservation,
                amountMinor: -$amountMinor,
                payoutRequestId: $request->id,
                note: "Reserved for {$request->reference}",
                currency: $currency,
            );

            return $request;
        }));
    }
}
