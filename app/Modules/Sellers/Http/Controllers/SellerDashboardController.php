<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Http\Controllers;

use App\Modules\Sellers\Concerns\CurrentSeller;
use App\Modules\Sellers\Enums\SellerPermission;
use App\Modules\Stores\Models\Store;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The seller's landing screen.
 *
 * Deliberately thin for M1: it answers "who am I, am I approved, and what
 * is left to set up". There are no trading figures here because there is
 * no trading yet — inventing them would be worse than an empty screen.
 */
final class SellerDashboardController
{
    public function __invoke(): Response
    {
        $membership = CurrentSeller::membership();
        $seller = $membership?->sellerAccount;

        abort_if($seller === null, 404);

        $store = Store::query()->where('seller_account_id', $seller->id)->first();

        // Setup steps are read from the record, not tracked in a separate
        // "onboarding progress" column that can drift out of step with it.
        $hasStore = $store !== null;

        $steps = [
            [
                'key' => 'store',
                'label' => 'Name your store and claim its address',
                'done' => $hasStore,
                'href' => '/seller/store',
            ],
            [
                'key' => 'branding',
                'label' => 'Upload a logo and banner',
                'done' => $hasStore && $store->logo_media_id !== null && $store->banner_media_id !== null,
                'href' => '/seller/store',
            ],
            [
                'key' => 'policies',
                'label' => 'Write your shipping and return policies',
                'done' => $hasStore && $store->shipping_policy !== null && $store->return_policy !== null,
                'href' => '/seller/store',
            ],
            [
                'key' => 'team',
                'label' => 'Invite the people who will run the store',
                'done' => $seller->memberships()->count() > 1,
                'href' => '/seller/team',
            ],
        ];

        return Inertia::render('Dashboard', [
            'seller' => [
                'legalName' => $seller->legal_name,
                'reference' => $seller->public_id,
                'status' => $seller->status->value,
                'role' => $membership->role->value,
                'roleLabel' => $membership->role->label(),
            ],
            'store' => $store === null ? null : [
                'name' => $store->name,
                'slug' => $store->slug,
                'isOpen' => $store->is_open,
                'publicUrl' => rtrim((string) config('veritas.identity.public_url'), '/').'/stores/'.$store->slug,
            ],
            'setup' => $steps,
            'can' => [
                'manageStore' => CurrentSeller::can(SellerPermission::StoreManage),
                'manageMembers' => CurrentSeller::can(SellerPermission::MembersManage),
            ],
        ]);
    }
}
