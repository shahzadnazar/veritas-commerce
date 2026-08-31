<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Actions;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Sellers\Enums\InvitationStatus;
use App\Modules\Sellers\Models\SellerInvitation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class RevokeSellerInvitation
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function __invoke(SellerInvitation $invitation, int $actorUserId): SellerInvitation
    {
        return DB::transaction(function () use ($invitation, $actorUserId): SellerInvitation {
            if (! $invitation->status->isRedeemable()) {
                throw new RuntimeException('That invitation is no longer open.');
            }

            $invitation->update([
                'status' => InvitationStatus::Revoked->value,
                'revoked_at' => Carbon::now(),
            ]);

            ($this->audit)(
                action: 'seller.member.invitation_revoked',
                actorType: 'seller',
                actorId: $actorUserId,
                subjectType: SellerInvitation::class,
                subjectId: $invitation->id,
                changes: ['email' => $invitation->email],
            );

            return $invitation;
        });
    }
}
