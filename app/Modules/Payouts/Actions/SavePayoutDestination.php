<?php

declare(strict_types=1);

namespace App\Modules\Payouts\Actions;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Payouts\Data\PayoutActor;
use App\Modules\Payouts\Enums\PayoutAccountType;
use App\Modules\Payouts\Models\PayoutAccount;
use App\Modules\Sellers\Concerns\CurrentSeller;
use App\Modules\Sellers\Models\SellerAccount;
use Illuminate\Support\Facades\DB;

/**
 * Where the seller's money goes. §58 and §59.
 *
 * Two rules make this safe, and neither is in the controller:
 *
 * FIRST, an open payout is not redirected. A destination change while a
 * request is in flight must not silently send approved money somewhere
 * new, and it does not — the request snapshotted its destination when it
 * was made and reads from that snapshot forever. Changing this record
 * affects the next payout, never one already asked for.
 *
 * SECOND, the previous destination is disabled rather than edited, and the
 * new one records when it appeared. A payout queue can then show finance
 * that the account changed two days before the withdrawal, which is the
 * oldest fraud pattern in this business and the one thing a reviewer most
 * needs to be told without asking.
 *
 * The password check that guards this lives at the HTTP edge, where the
 * credential is. Recording that it happened is here, in the audit trail.
 */
final class SavePayoutDestination
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function __invoke(
        SellerAccount $seller,
        string $displayLabel,
        PayoutActor $actor,
        PayoutAccountType $type = PayoutAccountType::Manual,
        ?string $last4 = null,
        ?string $country = null,
        string $currency = 'USD',
    ): PayoutAccount {
        $currency = strtoupper($currency);

        $account = CurrentSeller::actingAs($seller->id, fn (): PayoutAccount => DB::transaction(
            function () use ($seller, $displayLabel, $type, $last4, $country, $currency): PayoutAccount {
                $previous = PayoutAccount::query()
                    ->withoutGlobalScopes()
                    ->where('seller_account_id', $seller->id)
                    ->where('status', PayoutAccount::STATUS_ACTIVE)
                    ->lockForUpdate()
                    ->first();

                // Disabled, not deleted or overwritten: a payout made last
                // month must still be able to say where it went.
                $previous?->forceFill(['status' => PayoutAccount::STATUS_DISABLED])->save();

                return PayoutAccount::query()->create([
                    'seller_account_id' => $seller->id,
                    'type' => $type->value,
                    'provider' => $type === PayoutAccountType::Provider ? 'pending' : 'manual',
                    'display_label' => $displayLabel,
                    'last4' => $last4,
                    'country' => $country,
                    'currency' => $currency,
                    'status' => PayoutAccount::STATUS_ACTIVE,
                    'changed_at' => $previous === null ? null : now(),
                ]);
            }
        ));

        ($this->audit)(
            action: 'payouts.destination.changed',
            actorType: $actor->type,
            actorId: $actor->id,
            subjectType: 'seller_account',
            subjectId: $seller->id,
            // Nothing identifying beyond what a person would read off the
            // screen. There is no account number to leak here, and if
            // there ever is, RecordAuditEvent redacts by key name.
            changes: [
                'payout_account_id' => $account->id,
                'display_label' => $displayLabel,
                'type' => $type->value,
                'currency' => $currency,
                'last4' => $last4,
            ],
        );

        return $account;
    }
}
