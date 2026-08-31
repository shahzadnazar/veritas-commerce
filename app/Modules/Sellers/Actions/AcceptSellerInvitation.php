<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Actions;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Identity\Models\User;
use App\Modules\Sellers\Enums\InvitationStatus;
use App\Modules\Sellers\Models\SellerInvitation;
use App\Modules\Sellers\Models\SellerMembership;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Redeems an invitation.
 *
 * Single-use is enforced under a row lock: two simultaneous redemptions of
 * the same token cannot both create a membership. The address must match
 * the invitation, so a forwarded email does not let a different person in.
 */
final class AcceptSellerInvitation
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function __invoke(string $publicId, string $token, User $user): SellerMembership
    {
        return DB::transaction(function () use ($publicId, $token, $user): SellerMembership {
            $invitation = SellerInvitation::query()
                ->where('public_id', $publicId)
                ->lockForUpdate()
                ->first();

            if ($invitation === null || ! Hash::check($token, $invitation->token_hash)) {
                throw new RuntimeException('That invitation link is not valid.');
            }

            if ($invitation->hasExpired()) {
                $invitation->update(['status' => InvitationStatus::Expired->value]);

                throw new RuntimeException('That invitation has expired. Ask for a new one.');
            }

            if (! $invitation->status->isRedeemable()) {
                throw new RuntimeException('That invitation has already been used or withdrawn.');
            }

            if (strtolower($user->email) !== strtolower($invitation->email)) {
                throw new RuntimeException('This invitation was sent to a different address.');
            }

            $membership = SellerMembership::query()->firstOrCreate(
                ['seller_account_id' => $invitation->seller_account_id, 'user_id' => $user->id],
                ['role' => $invitation->role->value, 'accepted_at' => Carbon::now()],
            );

            $invitation->update([
                'status' => InvitationStatus::Accepted->value,
                'accepted_by_user_id' => $user->id,
                'accepted_at' => Carbon::now(),
            ]);

            ($this->audit)(
                action: 'seller.member.added',
                actorType: 'seller',
                actorId: $user->id,
                subjectType: SellerMembership::class,
                subjectId: $membership->id,
                changes: ['role' => $invitation->role->value],
            );

            return $membership;
        });
    }
}
