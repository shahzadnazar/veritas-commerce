<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Http\Controllers;

use App\Modules\Sellers\Actions\AcceptSellerInvitation;
use App\Modules\Sellers\Models\SellerInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Accepting an invitation.
 *
 * The invitation is looked up by its public id, and the token in the query
 * is what proves the holder was actually invited — the id alone opens
 * nothing.
 */
final class SellerInvitationController
{
    public function __construct(private readonly AcceptSellerInvitation $accept) {}

    public function show(Request $request, string $publicId): Response
    {
        $invitation = SellerInvitation::query()->where('public_id', $publicId)->firstOrFail();

        return Inertia::render('Invitation', [
            'invitation' => [
                'publicId' => $invitation->public_id,
                'storeName' => $invitation->sellerAccount?->store?->name
                    ?? $invitation->sellerAccount?->legal_name,
                'role' => $invitation->role->label(),
                'email' => $invitation->email,
                'status' => $invitation->status->value,
                'redeemable' => $invitation->isRedeemable(),
                'expiresAt' => $invitation->expires_at->toFormattedDateString(),
            ],
            'token' => (string) $request->query('token', ''),
        ]);
    }

    public function accept(Request $request, string $publicId): RedirectResponse
    {
        $validated = $request->validate(['token' => ['required', 'string']]);

        $user = $request->user('web');
        abort_if($user === null, 403);

        try {
            ($this->accept)($publicId, $validated['token'], $user);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['token' => $exception->getMessage()]);
        }

        return redirect()->route('seller.dashboard')->with('success', 'You have joined the store.');
    }
}
