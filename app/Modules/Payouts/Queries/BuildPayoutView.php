<?php

declare(strict_types=1);

namespace App\Modules\Payouts\Queries;

use App\Modules\Payouts\Enums\PayoutAllocationStatus;
use App\Modules\Payouts\Enums\PayoutStatus;
use App\Modules\Payouts\Enums\SettlementAttemptStatus;
use App\Modules\Payouts\Models\PayoutRequest;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Payouts as typed arrays, never as Eloquent graphs. §79.
 *
 * Two audiences with two different rights to the same record, and the
 * difference is enforced here rather than in a template:
 *
 *   summary/detail       what a seller sees about their own payout, and
 *                        what any admin who may read the queue sees.
 *   withSensitive: true  adds the destination reference and the account's
 *                        identifying metadata, and needs
 *                        payouts.view_sensitive.
 *
 * A settlement's external reference is in the ordinary detail on purpose:
 * it is a bank transfer number the seller is entitled to quote, not a
 * credential. What never appears in either shape is anything that could
 * move money — the platform holds none of it.
 */
final class BuildPayoutView
{
    /**
     * A row for a list. One row per payout, no per-row queries.
     *
     * @return array<string, mixed>
     */
    public function summarise(PayoutRequest $request): array
    {
        return [
            'id' => $request->public_id,
            'reference' => $request->reference,
            'status' => $request->status->value,
            'statusLabel' => $request->status->label(),
            'amountMinor' => $request->amount_minor,
            'amount' => $request->amount()->format(),
            'currency' => $request->currency,
            'requestedAt' => $request->requested_at->toIso8601String(),
            'decidedAt' => $request->decided_at?->toIso8601String(),
            'paidAt' => $request->paid_at?->toIso8601String(),
            'destinationLabel' => $request->destinationLabel(),
            'isOpen' => $request->status->holdsBalance(),
            'canCancel' => $request->status->isCancellableBySeller(),
        ];
    }

    /**
     * Everything one payout can say for itself. §20 and §22.
     *
     * Four bounded queries: allocations, settlement attempts, history, and
     * the ledger debit if there is one.
     *
     * @return array<string, mixed>
     */
    public function detail(PayoutRequest $request, bool $withSensitive = false): array
    {
        $allocations = DB::table('payout_allocations as a')
            ->join('seller_ledger_entries as e', 'e.id', '=', 'a.seller_ledger_entry_id')
            ->leftJoin('seller_orders as o', 'o.id', '=', 'e.seller_order_id')
            ->where('a.payout_request_id', $request->id)
            ->orderBy('a.id')
            ->select([
                'a.public_id', 'a.amount_minor', 'a.status', 'a.currency',
                'a.settled_at', 'a.released_at', 'e.created_at as earned_at',
                'o.reference as order_reference',
            ])
            ->get()
            ->map(static function (object $row): array {
                $status = PayoutAllocationStatus::from((string) $row->status);

                return [
                    'id' => (string) $row->public_id,
                    'amountMinor' => (int) $row->amount_minor,
                    'amount' => Money::of((int) $row->amount_minor, (string) $row->currency)->format(),
                    'currency' => (string) $row->currency,
                    'status' => $status->value,
                    'statusLabel' => $status->label(),
                    'earnedAt' => (string) $row->earned_at,
                    'orderReference' => $row->order_reference === null ? null : (string) $row->order_reference,
                    'settledAt' => $row->settled_at === null ? null : (string) $row->settled_at,
                    'releasedAt' => $row->released_at === null ? null : (string) $row->released_at,
                ];
            })
            ->all();

        $attempts = DB::table('payout_settlement_attempts')
            ->where('payout_request_id', $request->id)
            ->orderBy('id')
            ->get()
            ->map(static function (object $row): array {
                $status = SettlementAttemptStatus::from((string) $row->status);

                return [
                    'id' => (string) $row->public_id,
                    'provider' => (string) $row->provider,
                    'method' => $row->method === null ? null : (string) $row->method,
                    'reference' => $row->external_reference === null ? null : (string) $row->external_reference,
                    'status' => $status->value,
                    'statusLabel' => $status->label(),
                    'amountMinor' => (int) $row->amount_minor,
                    'amount' => Money::of((int) $row->amount_minor, (string) $row->currency)->format(),
                    'initiatedAt' => (string) $row->initiated_at,
                    'completedAt' => $row->completed_at === null ? null : (string) $row->completed_at,
                    'failureCode' => $row->failure_code === null ? null : (string) $row->failure_code,
                    'failureMessage' => $row->failure_message === null ? null : (string) $row->failure_message,
                ];
            })
            ->all();

        $history = DB::table('payout_status_history')
            ->where('payout_request_id', $request->id)
            ->orderBy('id')
            ->get()
            ->map(static function (object $row): array {
                $to = PayoutStatus::from((string) $row->to_status);

                return [
                    'from' => $row->from_status === null ? null : (string) $row->from_status,
                    'to' => $to->value,
                    'toLabel' => $to->label(),
                    'actorType' => $row->actor_type === null ? null : (string) $row->actor_type,
                    'actorLabel' => $row->actor_label === null ? null : (string) $row->actor_label,
                    'reason' => $row->reason === null ? null : (string) $row->reason,
                    'at' => (string) $row->created_at,
                ];
            })
            ->all();

        $debit = DB::table('seller_ledger_entries')
            ->where('payout_request_id', $request->id)
            ->where('type', 'payout')
            ->first(['public_id', 'amount_minor', 'created_at']);

        $detail = array_merge($this->summarise($request), [
            'sellerName' => $request->seller_name_snapshot,
            'destinationType' => $request->destination_type,
            'reviewedAt' => $request->reviewed_at?->toIso8601String(),
            'approvedAt' => $request->approved_at?->toIso8601String(),
            'cancelledAt' => $request->cancelled_at?->toIso8601String(),
            'failedAt' => $request->failed_at?->toIso8601String(),
            'decisionReason' => $request->decision_reason,
            'settlementMethod' => $request->settlement_method,
            'settlementReference' => $request->settlement_ref,
            'allocations' => $allocations,
            'settlementAttempts' => $attempts,
            'history' => $history,
            'ledgerDebit' => $debit === null ? null : [
                'id' => (string) $debit->public_id,
                'amountMinor' => (int) $debit->amount_minor,
                'amount' => Money::formatSigned((int) $debit->amount_minor, $request->currency),
                'postedAt' => (string) $debit->created_at,
            ],
        ]);

        if ($withSensitive) {
            $account = $request->payout_account_id === null
                ? null
                : DB::table('payout_accounts')->where('id', $request->payout_account_id)->first([
                    'public_id', 'type', 'provider', 'provider_account_reference',
                    'last4', 'country', 'currency', 'status', 'verified_at', 'changed_at',
                ]);

            $detail['destination'] = $account === null ? null : [
                'id' => (string) $account->public_id,
                'type' => (string) $account->type,
                'provider' => (string) $account->provider,
                'providerReference' => $account->provider_account_reference === null
                    ? null
                    : (string) $account->provider_account_reference,
                'last4' => $account->last4 === null ? null : (string) $account->last4,
                'country' => $account->country === null ? null : (string) $account->country,
                'currency' => (string) $account->currency,
                'status' => (string) $account->status,
                'verifiedAt' => $account->verified_at === null ? null : (string) $account->verified_at,
                // §21: a destination changed shortly before a withdrawal
                // is the oldest fraud pattern there is, so finance sees it
                // without having to go looking.
                'changedAt' => $account->changed_at === null ? null : (string) $account->changed_at,
            ];
        }

        return $detail;
    }
}
