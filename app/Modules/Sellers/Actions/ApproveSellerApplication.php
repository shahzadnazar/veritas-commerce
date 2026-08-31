<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Actions;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Sellers\Enums\SellerApplicationStatus;
use App\Modules\Sellers\Enums\SellerRole;
use App\Modules\Sellers\Enums\SellerStatus;
use App\Modules\Sellers\Events\SellerApproved;
use App\Modules\Sellers\Models\SellerAccount;
use App\Modules\Sellers\Models\SellerApplication;
use App\Modules\Sellers\Models\SellerMembership;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Approval as a domain operation, not a status assignment.
 *
 * One transaction creates the seller account, attaches the applicant as
 * Owner, links the application to the account and writes the history — so
 * a failure part-way leaves an application still awaiting a decision
 * rather than a seller with no owner, or an owner with no seller.
 *
 * IDEMPOTENCE. A double-clicked Approve, a retried request or a redelivered
 * queue job must not produce a second account or a second owner. Three
 * things guarantee that:
 *
 *   1. The application row is locked for update, so concurrent calls
 *      serialise rather than interleave.
 *   2. An already-approved application returns its existing account
 *      instead of building another.
 *   3. The membership is created with firstOrCreate, and the database
 *      carries a unique index on (seller_account_id, user_id) underneath
 *      it — so even a path that skipped this action cannot duplicate one.
 */
final class ApproveSellerApplication
{
    public function __construct(
        private readonly TransitionSellerApplication $transition,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function __invoke(SellerApplication $application, int $decidedByAdminId): SellerAccount
    {
        return DB::transaction(function () use ($application, $decidedByAdminId): SellerAccount {
            /** @var SellerApplication $locked */
            $locked = SellerApplication::query()
                ->whereKey($application->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // Already approved: hand back what exists. This is the branch a
            // double-click takes, and it must be a no-op.
            if ($locked->status === SellerApplicationStatus::Approved) {
                $existing = $locked->seller_account_id !== null
                    ? SellerAccount::query()->find($locked->seller_account_id)
                    : null;

                if ($existing !== null) {
                    return $existing;
                }
            }

            $seller = SellerAccount::query()->create([
                'application_id' => $locked->id,
                'legal_name' => $locked->legal_name,
                'business_type' => $locked->business_type,
                'tax_id' => $locked->tax_id,
                'status' => SellerStatus::Approved->value,
                'ships_from_city' => $locked->address_city,
                'ships_from_state' => $locked->address_state,
            ]);

            $seller->forceFill(['approved_at' => Carbon::now()])->save();

            // firstOrCreate over a uniquely-indexed pair: the index is the
            // real guarantee, this is the friendly path to it.
            SellerMembership::query()->firstOrCreate(
                ['seller_account_id' => $seller->id, 'user_id' => $locked->user_id],
                ['role' => SellerRole::Owner->value, 'accepted_at' => Carbon::now()],
            );

            $locked->seller_account_id = $seller->id;
            $locked->decided_by_admin_id = $decidedByAdminId;
            $locked->save();

            ($this->transition)(
                application: $locked,
                to: SellerApplicationStatus::Approved,
                actorType: 'admin',
                actorId: $decidedByAdminId,
            );

            ($this->audit)(
                action: 'seller.approved',
                actorType: 'admin',
                actorId: $decidedByAdminId,
                subjectType: SellerAccount::class,
                subjectId: $seller->id,
                changes: ['application_reference' => $locked->reference],
            );

            // Dispatched after commit so a listener never sees a seller
            // that a later rollback removed.
            DB::afterCommit(function () use ($seller, $locked): void {
                Event::dispatch(new SellerApproved(
                    sellerAccountId: $seller->id,
                    applicationId: $locked->id,
                    ownerUserId: $locked->user_id,
                ));
            });

            return $seller;
        });
    }
}
