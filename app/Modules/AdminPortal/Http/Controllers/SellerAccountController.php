<?php

declare(strict_types=1);

namespace App\Modules\AdminPortal\Http\Controllers;

use App\Modules\AdminPortal\Http\Requests\DecisionRequest;
use App\Modules\Identity\Enums\AdminPermission;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Sellers\Actions\ChangeSellerStatus;
use App\Modules\Sellers\Enums\SellerStatus;
use App\Modules\Sellers\Models\SellerAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Seller governance, separate from application review.
 *
 * Suspending a trading seller and rejecting an applicant are different
 * decisions with different consequences, so they are different screens and
 * different permissions.
 */
final class SellerAccountController
{
    public function __construct(private readonly ChangeSellerStatus $changeStatus) {}

    public function index(Request $request): Response
    {
        $this->authorize($request, AdminPermission::SellerApplicationView);

        $sellers = SellerAccount::query()
            ->with('store')
            ->when(
                $request->string('status')->toString() !== '',
                fn ($query) => $query->where('status', $request->string('status')->toString()),
            )
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Sellers/Accounts', [
            'sellers' => [
                'data' => array_map(
                    static fn (SellerAccount $seller): array => [
                        'publicId' => $seller->public_id,
                        'legalName' => $seller->legal_name,
                        'storeName' => $seller->store?->name,
                        'storeSlug' => $seller->store?->slug,
                        'status' => $seller->status->value,
                        'approvedAt' => $seller->approved_at?->toDateString(),
                        'suspensionReason' => $seller->suspension_reason,
                    ],
                    $sellers->items(),
                ),
                'currentPage' => $sellers->currentPage(),
                'lastPage' => $sellers->lastPage(),
                'total' => $sellers->total(),
            ],
            'filters' => ['status' => $request->string('status')->toString()],
            'can' => [
                'suspend' => $this->admin($request)->role->can(AdminPermission::SellerSuspend),
                'reactivate' => $this->admin($request)->role->can(AdminPermission::SellerReactivate),
            ],
        ]);
    }

    public function suspend(DecisionRequest $request, string $publicId): RedirectResponse
    {
        $this->authorize($request, AdminPermission::SellerSuspend);

        ($this->changeStatus)(
            seller: $this->find($publicId),
            to: SellerStatus::Suspended,
            adminId: $this->admin($request)->id,
            reason: $request->reason(),
        );

        return back()->with('success', 'Seller suspended.');
    }

    public function reactivate(Request $request, string $publicId): RedirectResponse
    {
        $this->authorize($request, AdminPermission::SellerReactivate);

        ($this->changeStatus)(
            seller: $this->find($publicId),
            to: SellerStatus::Approved,
            adminId: $this->admin($request)->id,
        );

        return back()->with('success', 'Seller reactivated.');
    }

    private function find(string $publicId): SellerAccount
    {
        return SellerAccount::query()->where('public_id', $publicId)->firstOrFail();
    }

    private function admin(Request $request): AdminUser
    {
        $admin = $request->user('admin');

        abort_if(! $admin instanceof AdminUser, 403);

        return $admin;
    }

    private function authorize(Request $request, AdminPermission $permission): void
    {
        abort_unless($this->admin($request)->role->can($permission), 403);
    }
}
