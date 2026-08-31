<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Http\Controllers;

use App\Modules\Sellers\Actions\InviteSellerMember;
use App\Modules\Sellers\Actions\RemoveSellerMember;
use App\Modules\Sellers\Actions\RevokeSellerInvitation;
use App\Modules\Sellers\Concerns\CurrentSeller;
use App\Modules\Sellers\Enums\InvitationStatus;
use App\Modules\Sellers\Enums\SellerPermission;
use App\Modules\Sellers\Enums\SellerRole;
use App\Modules\Sellers\Models\SellerAccount;
use App\Modules\Sellers\Models\SellerInvitation;
use App\Modules\Sellers\Models\SellerMembership;
use App\Modules\Sellers\Notifications\SellerInvitationNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * The seller's team.
 *
 * Every lookup is scoped to the acting seller, so a membership or
 * invitation id belonging to another store simply does not resolve.
 */
final class SellerTeamController
{
    public function __construct(
        private readonly InviteSellerMember $invite,
        private readonly RevokeSellerInvitation $revoke,
        private readonly RemoveSellerMember $remove,
    ) {}

    public function index(): Response
    {
        $sellerId = $this->sellerId();

        $members = SellerMembership::query()
            ->with('user')
            ->where('seller_account_id', $sellerId)
            ->get()
            ->map(fn (SellerMembership $membership): array => [
                'id' => $membership->id,
                'name' => $membership->user?->fullName(),
                'email' => $membership->user?->email,
                'role' => $membership->role->value,
                'roleLabel' => $membership->role->label(),
                'acceptedAt' => $membership->accepted_at?->toDateString(),
            ])
            ->all();

        $invitations = SellerInvitation::query()
            ->where('seller_account_id', $sellerId)
            ->whereIn('status', [InvitationStatus::Pending->value, InvitationStatus::Expired->value])
            ->get()
            ->map(fn (SellerInvitation $invitation): array => [
                'publicId' => $invitation->public_id,
                'email' => $invitation->email,
                'role' => $invitation->role->value,
                'status' => $invitation->status->value,
                'expiresAt' => $invitation->expires_at->toDateString(),
            ])
            ->all();

        return Inertia::render('Team/Index', [
            'members' => $members,
            'invitations' => $invitations,
            'roles' => array_map(
                static fn (SellerRole $role): array => ['value' => $role->value, 'label' => $role->label()],
                SellerRole::cases(),
            ),
            'can' => ['manage' => CurrentSeller::can(SellerPermission::MembersManage)],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'role' => ['required', Rule::enum(SellerRole::class)],
        ]);

        $seller = SellerAccount::query()->findOrFail($this->sellerId());
        $user = $request->user('web');
        abort_if($user === null, 403);

        try {
            $result = ($this->invite)(
                seller: $seller,
                email: $validated['email'],
                role: SellerRole::from($validated['role']),
                invitedByUserId: $user->getAuthIdentifier(),
            );
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['email' => $exception->getMessage()]);
        }

        // The plaintext token exists here and in the email, nowhere else.
        Notification::route('mail', $validated['email'])->notify(
            new SellerInvitationNotification(
                storeName: $seller->store?->name ?? $seller->legal_name,
                invitationPublicId: $result['invitation']->public_id,
                token: $result['token'],
                expiresAt: $result['invitation']->expires_at,
            ),
        );

        return back()->with('success', 'Invitation sent.');
    }

    public function revoke(Request $request, string $publicId): RedirectResponse
    {
        $invitation = SellerInvitation::query()
            ->where('seller_account_id', $this->sellerId())
            ->where('public_id', $publicId)
            ->firstOrFail();

        $user = $request->user('web');
        abort_if($user === null, 403);

        ($this->revoke)($invitation, $user->getAuthIdentifier());

        return back()->with('success', 'Invitation withdrawn.');
    }

    public function destroy(Request $request, int $membershipId): RedirectResponse
    {
        $membership = SellerMembership::query()
            ->where('seller_account_id', $this->sellerId())
            ->whereKey($membershipId)
            ->firstOrFail();

        $user = $request->user('web');
        abort_if($user === null, 403);

        try {
            ($this->remove)($membership, $user->getAuthIdentifier());
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['member' => $exception->getMessage()]);
        }

        return back()->with('success', 'Member removed.');
    }

    private function sellerId(): int
    {
        $sellerId = CurrentSeller::id();
        abort_if($sellerId === null, 404);

        return $sellerId;
    }
}
