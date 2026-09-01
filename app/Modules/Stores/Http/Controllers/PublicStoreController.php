<?php

declare(strict_types=1);

namespace App\Modules\Stores\Http\Controllers;

use App\Modules\Catalog\Queries\BuildDiscoveryPage;
use App\Modules\Catalog\Queries\SearchQueryFactory;
use App\Modules\Catalog\Support\Indexability;
use App\Modules\Events\Actions\RecordInteraction;
use App\Modules\Events\Enums\InteractionEventType;
use App\Modules\Stores\Queries\FindPublicStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public seller store page.
 *
 * M1 delivered the shell; M3 fills it with what this seller actually
 * sells. The grid is the same discovery engine, cards and facets as
 * search and category pages, scoped to one seller — so a store page
 * cannot show a product on different terms from the rest of the site.
 *
 * Scoped in the query, not filtered afterwards: another seller's offer has
 * no path into this listing.
 */
final class PublicStoreController
{
    public function __construct(
        private readonly FindPublicStore $findStore,
        private readonly SearchQueryFactory $queries,
        private readonly BuildDiscoveryPage $page,
        private readonly RecordInteraction $interactions,
    ) {}

    public function __invoke(Request $request, string $slug): Response|RedirectResponse
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

        $query = ($this->queries)($request, sellerAccountId: $store->seller_account_id);
        $page = ($this->page)($query);

        $this->interactions->record(
            $request,
            InteractionEventType::SellerStoreViewed,
            sellerAccountId: $store->seller_account_id,
            payload: ['context' => 'store', 'store' => $store->slug],
        );

        return Inertia::render('Store/Show', [
            ...$page,
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
                // A filtered or paged view of it is not either.
                'robots' => $store->is_open
                    ? Indexability::forListing($canonical, $query)['robots']
                    : Indexability::NOINDEX,
            ],
        ]);
    }
}
