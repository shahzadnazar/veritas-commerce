<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Actions;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Sellers\Enums\SellerRole;
use App\Modules\Sellers\Enums\SellerStatus;
use App\Modules\Sellers\Models\SellerAccount;
use App\Modules\Sellers\Notifications\SellerStatusChanged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Suspends or reactivates a seller.
 *
 * Suspension never deletes anything. Orders, ledger entries, payouts,
 * inventory and audit records all survive intact — a suspended seller
 * still owes their customers fulfilment, and the platform still owes them
 * their balance. What suspension takes away is the ability to act.
 */
final class ChangeSellerStatus
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function __invoke(
        SellerAccount $seller,
        SellerStatus $to,
        int $adminId,
        ?string $reason = null,
    ): SellerAccount {
        $from = $seller->status;

        if (! in_array($to, $from->allowedTransitions(), true)) {
            throw new RuntimeException("A seller cannot move from {$from->value} to {$to->value}.");
        }

        if ($to->requiresReason() && trim((string) $reason) === '') {
            throw new RuntimeException("Moving a seller to {$to->value} requires a written reason.");
        }

        return DB::transaction(function () use ($seller, $from, $to, $adminId, $reason): SellerAccount {
            $seller->status = $to;

            if ($to === SellerStatus::Suspended) {
                $seller->suspended_at = Carbon::now();
                $seller->suspension_reason = $reason;
            }

            if ($to === SellerStatus::Approved && $from === SellerStatus::Suspended) {
                $seller->suspended_at = null;
                $seller->suspension_reason = null;
            }

            $seller->save();

            DB::table('seller_account_events')->insert([
                'seller_account_id' => $seller->id,
                'event' => $to === SellerStatus::Suspended ? 'suspended' : 'status_changed',
                'actor_type' => 'admin',
                'actor_id' => $adminId,
                'note' => $reason,
                'created_at' => Carbon::now(),
            ]);

            ($this->audit)(
                action: $to === SellerStatus::Suspended ? 'seller.suspended' : 'seller.reactivated',
                actorType: 'admin',
                actorId: $adminId,
                subjectType: SellerAccount::class,
                subjectId: $seller->id,
                changes: ['status' => ['from' => $from->value, 'to' => $to->value]],
                reason: $reason,
            );

            // The owners are told, after commit. Every owner, not just the
            // one who applied: a store with two owners has two people who
            // need to know it has gone dark.
            DB::afterCommit(function () use ($seller, $to, $reason): void {
                $storeName = $seller->store()->value('name') ?? $seller->legal_name;

                $owners = $seller->memberships()
                    ->where('role', SellerRole::Owner->value)
                    ->with('user')
                    ->get();

                foreach ($owners as $membership) {
                    $membership->user?->notify(new SellerStatusChanged(
                        storeName: $storeName,
                        status: $to,
                        reason: $reason,
                    ));
                }
            });

            return $seller;
        });
    }
}
