<?php

declare(strict_types=1);

namespace Tests\Feature\Payouts;

use App\Modules\Ledger\Actions\PostLedgerEntry;
use App\Modules\Ledger\Enums\LedgerEntryStatus;
use App\Modules\Ledger\Enums\LedgerEntryType;
use App\Modules\Ledger\Models\SellerLedgerEntry;
use App\Modules\Payouts\Actions\RequestPayout;
use App\Modules\Payouts\Data\PayoutActor;
use App\Modules\Payouts\Data\SellerFinancialPosition;
use App\Modules\Payouts\Models\PayoutAccount;
use App\Modules\Payouts\Models\PayoutRequest;
use App\Modules\Payouts\Queries\GetSellerFinancialPosition;
use App\Modules\Sellers\Models\SellerAccount;
use Illuminate\Support\Carbon;

/**
 * Seller finance fixtures, built through the ledger rather than around it.
 *
 * The earnings here are posted with PostLedgerEntry — the same action a
 * verified payment uses — so a test that proves something about a balance
 * is proving it about the real thing. Where a test needs the whole chain
 * from a customer paying to money becoming available it uses the M5/M6
 * fixtures instead; these are for the finance arithmetic itself.
 */
trait BuildsSellerFinance
{
    /** An earning the seller can already spend. */
    protected function availableEarning(SellerAccount $seller, int $minor, string $currency = 'USD'): SellerLedgerEntry
    {
        return app(PostLedgerEntry::class)(
            seller: $seller,
            type: LedgerEntryType::SaleEarning,
            amountMinor: $minor,
            status: LedgerEntryStatus::Available,
            availableAt: Carbon::now()->subDay(),
            currency: $currency,
        );
    }

    /** An earning still inside its clearing window. */
    protected function clearingEarning(SellerAccount $seller, int $minor, string $currency = 'USD'): SellerLedgerEntry
    {
        return app(PostLedgerEntry::class)(
            seller: $seller,
            type: LedgerEntryType::SaleEarning,
            amountMinor: $minor,
            status: LedgerEntryStatus::Clearing,
            availableAt: Carbon::now()->addDays(7),
            currency: $currency,
        );
    }

    /** An earning whose order has not been delivered yet. */
    protected function pendingEarning(SellerAccount $seller, int $minor, string $currency = 'USD'): SellerLedgerEntry
    {
        return app(PostLedgerEntry::class)(
            seller: $seller,
            type: LedgerEntryType::SaleEarning,
            amountMinor: $minor,
            status: LedgerEntryStatus::Pending,
            currency: $currency,
        );
    }

    /** A refund reversal in whatever state the earning it cancels is in. */
    protected function reversal(
        SellerAccount $seller,
        int $minor,
        LedgerEntryStatus $status = LedgerEntryStatus::Available,
        string $currency = 'USD',
    ): SellerLedgerEntry {
        return app(PostLedgerEntry::class)(
            seller: $seller,
            type: LedgerEntryType::RefundReversal,
            amountMinor: -abs($minor),
            status: $status,
            currency: $currency,
        );
    }

    protected function destination(SellerAccount $seller, string $currency = 'USD'): PayoutAccount
    {
        return PayoutAccount::factory()->create([
            'seller_account_id' => $seller->id,
            'currency' => $currency,
        ]);
    }

    protected function requestPayout(SellerAccount $seller, int $minor, string $currency = 'USD'): PayoutRequest
    {
        return app(RequestPayout::class)(
            seller: $seller,
            amountMinor: $minor,
            currency: $currency,
            actor: PayoutActor::seller(null, 'Test owner'),
        );
    }

    protected function positionOf(SellerAccount $seller, string $currency = 'USD'): SellerFinancialPosition
    {
        return app(GetSellerFinancialPosition::class)($seller->id, $currency);
    }
}
