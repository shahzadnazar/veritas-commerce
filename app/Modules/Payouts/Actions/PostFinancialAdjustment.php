<?php

declare(strict_types=1);

namespace App\Modules\Payouts\Actions;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Ledger\Actions\PostLedgerEntry;
use App\Modules\Ledger\Enums\LedgerEntryStatus;
use App\Modules\Ledger\Enums\LedgerEntryType;
use App\Modules\Ledger\Models\SellerLedgerEntry;
use App\Modules\Payouts\Data\PayoutActor;
use App\Modules\Payouts\Exceptions\PayoutNotPermitted;
use App\Modules\Sellers\Models\SellerAccount;
use Illuminate\Support\Str;

/**
 * A correction, made by finance, that the ledger records forever. §64.
 *
 * This exists because real marketplaces have to correct things: a
 * commission applied at the wrong rate, a goodwill credit after an
 * incident, a duplicate charge that has to come back off. What it is NOT
 * is a way to edit history — the original rows stay exactly as they are
 * and a new entry is appended beside them, which is the same rule every
 * other financial write in this system follows.
 *
 * §65, the policy on what an adjustment does to withdrawable money:
 *
 *   A CREDIT lands in CLEARING, not AVAILABLE. Money the platform hands a
 *   seller waits the same clearing period as money a customer paid them,
 *   so an adjustment cannot be used — accidentally or otherwise — to route
 *   funds past the protection the clearing window exists to give. A
 *   finance admin who genuinely needs it available immediately posts it
 *   and it clears on the ordinary schedule.
 *
 *   A DEBIT lands in AVAILABLE, so it bites at once. Money the seller owes
 *   back should reduce what they can withdraw now rather than waiting a
 *   week to do so.
 *
 * A reason is mandatory and is written to the audit trail with the actor
 * and the amount. An adjustment nobody can explain later is indisputably
 * worse than no adjustment at all.
 */
final class PostFinancialAdjustment
{
    public function __construct(
        private readonly PostLedgerEntry $ledger,
        private readonly RecordAuditEvent $audit,
    ) {}

    /**
     * @param  int  $amountMinor  signed: positive credits the seller, negative debits them
     */
    public function __invoke(
        SellerAccount $seller,
        int $amountMinor,
        string $reason,
        PayoutActor $actor,
        string $currency = 'USD',
    ): SellerLedgerEntry {
        if ($amountMinor === 0) {
            throw PayoutNotPermitted::notPositive($amountMinor, $currency);
        }

        if (trim($reason) === '') {
            throw PayoutNotPermitted::reasonRequired('adjust');
        }

        $status = $amountMinor > 0
            ? LedgerEntryStatus::Clearing
            : LedgerEntryStatus::Available;

        $entry = ($this->ledger)(
            seller: $seller,
            type: LedgerEntryType::Adjustment,
            amountMinor: $amountMinor,
            status: $status,
            availableAt: $amountMinor > 0 ? now()->addDays($seller->clearingPeriodDays()) : now(),
            note: mb_substr($reason, 0, 500),
            currency: $currency,
            // Unique per adjustment: two deliberate corrections of the
            // same amount on the same day are two corrections, so this
            // does not collapse them the way a replayed event key would.
            sourceKey: 'adjustment:'.Str::ulid(),
        );

        ($this->audit)(
            action: $amountMinor > 0 ? 'finance.adjustment.credit' : 'finance.adjustment.debit',
            actorType: $actor->type,
            actorId: $actor->id,
            subjectType: 'seller_account',
            subjectId: $seller->id,
            changes: [
                'ledger_entry_id' => $entry->id,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'status' => $status->value,
            ],
            reason: $reason,
        );

        return $entry;
    }
}
