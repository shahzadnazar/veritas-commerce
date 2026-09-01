<?php

declare(strict_types=1);

namespace App\Modules\Stores\Http\Controllers;

use App\Modules\Stores\Queries\FindPublicStore;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public seller store page.
 *
 * M1 delivers the shell: branding, description, policies and the SEO
 * identity. The product grid arrives with the catalogue in M2, and the
 * empty state says so rather than showing invented cards.
 */
final class PublicStoreController
{
    public function __construct(private readonly FindPublicStore $findStore) {}

    public function __invoke(string $slug): Response|RedirectResponse
    {
        $store = ($this->findStore)($slug);

        if ($store === null) {
            // A renamed store keeps its search equity: the old address
            // redirects permanently rather than 404ing.
            $current = $this->findStore->currentSlugForOldSlug($slug);

            if ($current !== null) {
                return redirect()->route('stores.show', ['slug' => $current], 301);
            }

            abort(404);
        }

        $canonical = rtrim((string) config('veritas.identity.public_url'), '/').'/stores/'.$store->slug;

        return Inertia::render('Store/Show', [
            'store' => [
                'name' => $store->name,
                'slug' => $store->slug,
                'description' => $store->description,
                'supportEmail' => $store->support_email,
                'shippingPolicy' => $store->shipping_policy,
                'returnPolicy' => $store->return_policy,
                'isOpen' => $store->is_open,
                'shipsFrom' => trim(implode(', ', array_filter([
                    $store->business_city,
                    $store->business_state,
                ]))),
            ],
            'seo' => [
                'title' => $store->name,
                'description' => $store->description !== null
                    ? mb_substr($store->description, 0, 155)
                    : $store->name.' on '.config('veritas.identity.display_name').'.',
                'canonical' => $canonical,
                'ogTitle' => $store->name,
                'ogType' => 'website',
                'ogUrl' => $canonical,
                // A store that is closed today may be open next week, so
                // the URL stays — but it is not what should be indexed.
                'robots' => $store->is_open ? 'index, follow' : 'noindex, follow',
            ],
        ]);
    }
}
