<?php

declare(strict_types=1);

namespace App\Modules\Sellers\Http\Controllers;

use App\Modules\Sellers\Concerns\CurrentSeller;
use App\Modules\Stores\Actions\UpdateStore;
use App\Modules\Stores\Http\Requests\UpdateStoreRequest;
use App\Modules\Stores\Models\Store;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Store setup.
 *
 * The store is resolved from the acting membership, never from the URL, so
 * there is no id for a request to tamper with.
 */
final class SellerStoreController
{
    public function __construct(private readonly UpdateStore $updateStore) {}

    public function edit(): Response
    {
        $sellerId = CurrentSeller::id();
        abort_if($sellerId === null, 404);

        $store = Store::query()->where('seller_account_id', $sellerId)->first();

        return Inertia::render('Store/Edit', [
            'store' => $store === null ? null : [
                'name' => $store->name,
                'slug' => $store->slug,
                'description' => $store->description,
                'supportEmail' => $store->support_email,
                'supportPhone' => $store->support_phone,
                'shippingPolicy' => $store->shipping_policy,
                'returnPolicy' => $store->return_policy,
                'timezone' => $store->timezone,
                'businessCity' => $store->business_city,
                'businessState' => $store->business_state,
                'businessCountry' => $store->business_country,
                'isOpen' => $store->is_open,
                'hasLogo' => $store->logo_media_id !== null,
                'hasBanner' => $store->banner_media_id !== null,
            ],
            'publicUrlBase' => rtrim((string) config('veritas.identity.public_url'), '/').'/stores/',
        ]);
    }

    public function update(UpdateStoreRequest $request): RedirectResponse
    {
        $sellerId = CurrentSeller::id();
        abort_if($sellerId === null, 404);

        ($this->updateStore)(
            sellerAccountId: $sellerId,
            attributes: $request->safe()->except(['logo', 'banner']),
            images: [
                'logo' => $request->file('logo'),
                'banner' => $request->file('banner'),
            ],
        );

        return back()->with('success', 'Your store is saved.');
    }
}
