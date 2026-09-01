<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Actions;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Identity\Models\User;
use App\Modules\Sellers\Enums\InvitationStatus;
use App\Modules\Sellers\Enums\SellerRole;
use App\Modules\Sellers\Models\SellerAccount;
use App\Modules\Sellers\Models\SellerInvitation;
use App\Modules\Sellers\Models\SellerMembership;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Invites someone to join a seller organisation.
 *
 * The token is returned once, for the email, and only its hash is stored —
 * a leaked table must not let anyone join a store. The invitation expires,
 * is single-use, and is revocable.
 */
final class InviteSellerMember
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    /** @return array{invitation: SellerInvitation, token: string} */
    public function __invoke(
        SellerAccount $seller,
        string $email,
        SellerRole $role,
        int $invitedByUserId,
    ): array {
        $email = strtolower(trim($email));

        return DB::transaction(function () use ($seller, $email, $role, $invitedByUserId): array {
            $alreadyMember = SellerMembership::query()
                ->where('seller_account_id', $seller->id)
                // Membership is by user id; the address only identifies
                // which user. Saying that directly is one query and one
                // unambiguous column.
                ->whereIn('user_id', User::query()->where('email', $email)->select('id'))
                ->exists();

            if ($alreadyMember) {
                throw new RuntimeException('That person is already a member of this store.');
            }

            $live = SellerInvitation::query()
                ->where('seller_account_id', $seller->id)
                ->whereRaw('lower(email) = ?', [$email])
                ->where('status', InvitationStatus::Pending->value)
                ->exists();

            if ($live) {
                throw new RuntimeException('An invitation is already open for that address.');
            }

            $token = Str::random(48);

            $invitation = SellerInvitation::query()->create([
                'seller_account_id' => $seller->id,
                'email' => $email,
                'role' => $role->value,
                'token_hash' => Hash::make($token),
                'status' => InvitationStatus::Pending->value,
                'invited_by_user_id' => $invitedByUserId,
                'expires_at' => Carbon::now()->addDays((int) config('veritas.sellers.invitation_expiry_days')),
            ]);

            ($this->audit)(
                action: 'seller.member.invited',
                actorType: 'seller',
                actorId: $invitedByUserId,
                subjectType: SellerAccount::class,
                subjectId: $seller->id,
                // The address and role are the record; the token is not.
                changes: ['email' => $email, 'role' => $role->value],
            );

            return ['invitation' => $invitation, 'token' => $token];
        });
    }
}
