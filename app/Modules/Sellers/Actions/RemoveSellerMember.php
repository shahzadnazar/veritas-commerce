<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Actions;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Sellers\Enums\SellerRole;
use App\Modules\Sellers\Models\SellerMembership;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Removes a member from a seller organisation.
 *
 * The last owner cannot be removed: a store with no owner has nobody who
 * can restore access, invite anyone, or request its money.
 */
final class RemoveSellerMember
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function __invoke(SellerMembership $membership, int $actorUserId): void
    {
        DB::transaction(function () use ($membership, $actorUserId): void {
            if ($membership->role === SellerRole::Owner) {
                $otherOwners = SellerMembership::query()
                    ->where('seller_account_id', $membership->seller_account_id)
                    ->where('role', SellerRole::Owner->value)
                    ->whereKeyNot($membership->getKey())
                    ->count();

                if ($otherOwners === 0) {
                    throw new RuntimeException('A store must keep at least one owner.');
                }
            }

            ($this->audit)(
                action: 'seller.member.removed',
                actorType: 'seller',
                actorId: $actorUserId,
                subjectType: SellerMembership::class,
                subjectId: $membership->id,
                changes: ['role' => $membership->role->value],
            );

            $membership->delete();
        });
    }
}
