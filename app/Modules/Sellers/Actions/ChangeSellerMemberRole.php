<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Actions;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Sellers\Enums\SellerRole;
use App\Modules\Sellers\Models\SellerMembership;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Changes what a member of a seller organisation may do.
 *
 * The same rule as removal applies, for the same reason: demoting the last
 * owner leaves a store nobody can restore access to, invite into, or draw
 * money from. It is refused rather than warned about.
 */
final class ChangeSellerMemberRole
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function __invoke(SellerMembership $membership, SellerRole $to, int $actorUserId): SellerMembership
    {
        return DB::transaction(function () use ($membership, $to, $actorUserId): SellerMembership {
            $from = $membership->role;

            if ($from === $to) {
                return $membership;
            }

            if ($from === SellerRole::Owner) {
                $otherOwners = SellerMembership::query()
                    ->where('seller_account_id', $membership->seller_account_id)
                    ->where('role', SellerRole::Owner->value)
                    ->whereKeyNot($membership->getKey())
                    ->count();

                if ($otherOwners === 0) {
                    throw new RuntimeException('A store must keep at least one owner.');
                }
            }

            $membership->role = $to;
            $membership->save();

            ($this->audit)(
                action: 'seller.member.role_changed',
                actorType: 'seller',
                actorId: $actorUserId,
                subjectType: SellerMembership::class,
                subjectId: $membership->id,
                changes: ['role' => ['from' => $from->value, 'to' => $to->value]],
            );

            return $membership;
        });
    }
}
