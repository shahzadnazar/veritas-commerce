<?php

declare(strict_types=1);

namespace App\Support\Performance;

use App\Modules\AdminPortal\Queries\ProductModerationQueue;
use App\Modules\AdminPortal\Queries\SearchHealth;
use App\Modules\AdminPortal\Queries\SellerApplicationQueue;
use App\Modules\Analytics\Enums\AnalyticsPeriod;
use App\Modules\Analytics\Queries\GetMarketplaceAnalytics;
use App\Modules\Analytics\Queries\GetSellerAnalytics;
use App\Modules\Catalog\Queries\BuildDiscoveryPage;
use App\Modules\Catalog\Queries\BuildProductPage;
use App\Modules\Catalog\Queries\FindPublicProduct;
use App\Modules\Catalog\Queries\SearchQueryFactory;
use App\Modules\Catalog\Queries\SitemapUrls;
use App\Modules\Customers\Queries\GetWishlist;
use App\Modules\Offers\Queries\InventoryRows;
use App\Modules\Orders\Models\SellerOrder;
use App\Modules\Orders\Queries\BuildFulfilmentView;
use App\Modules\Orders\Queries\RecentSellerOrders;
use App\Modules\Payouts\Models\PayoutRequest;
use App\Modules\Payouts\Queries\BuildPayoutView;
use App\Modules\Payouts\Queries\BuildSellerStatement;
use App\Modules\Payouts\Queries\GetSellerFinancialPosition;
use App\Modules\Payouts\Queries\SummarisePlatformFinance;
use App\Modules\Recommendations\Data\RecommendationRequest;
use App\Modules\Recommendations\Enums\AssociationKind;
use App\Modules\Recommendations\Enums\RecommendationSlot;
use App\Modules\Recommendations\Queries\GetPopularProducts;
use App\Modules\Recommendations\Queries\GetProductAssociations;
use App\Modules\Recommendations\Strategies\PersonalAffinityStrategy;
use App\Modules\Recommendations\Strategies\RecentlyViewedStrategy;
use App\Modules\Reviews\Queries\BuildModerationQueue;
use App\Modules\Reviews\Queries\GetProductReviews;
use App\Modules\Sellers\Concerns\CurrentSeller;
use App\Modules\Stores\Queries\FindPublicStore;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The read surfaces the audit measures, and the rows it measures them on.
 *
 * Two decisions shape this file.
 *
 * The surfaces are the application's own query objects, invoked the way
 * the controllers invoke them. Writing representative SQL by hand would
 * have been easier and would have measured the SQL somebody wrote for the
 * benchmark rather than the SQL Eloquent emits — including the count
 * query behind every paginator and the second statement behind every
 * eager load, which are exactly the ones nobody thinks about.
 *
 * And the fixtures are the worst rows in the dataset, not the first ones.
 * A seller order page is measured against the seller with the most
 * orders, a product page against the product with the most offers, a
 * statement against the ledger with the most entries. Measuring the
 * median row would report the number that never causes an incident.
 */
final class ReadSurfaces
{
    /** @var array<string, int|string> */
    private array $fixtures = [];

    /**
     * @return array<int, array{group: string, name: string, run: Closure}>
     */
    public function all(): array
    {
        $this->resolveFixtures();

        return array_merge(
            $this->discovery(),
            $this->sellerPortal(),
            $this->adminConsole(),
            $this->customerAccount(),
        );
    }

    /** @return array<string, int|string> */
    public function fixtures(): array
    {
        return $this->fixtures;
    }

    /**
     * The heaviest row of each kind, found once.
     *
     * `withoutScope` because the seller global scope is not active here
     * and these are cross-tenant lookups by construction — the harness is
     * choosing which tenant to measure, which is the one thing no request
     * is ever allowed to do.
     */
    private function resolveFixtures(): void
    {
        if ($this->fixtures !== []) {
            return;
        }

        $busiestSeller = DB::table('seller_orders')
            ->select('seller_account_id')
            ->groupBy('seller_account_id')
            ->orderByRaw('count(*) desc')
            ->limit(1)
            ->value('seller_account_id');

        $contestedProduct = DB::table('offers')
            ->select('product_id')
            ->where('status', 'published')
            ->groupBy('product_id')
            ->orderByRaw('count(*) desc')
            ->limit(1)
            ->value('product_id');

        $busiestCustomer = DB::table('marketplace_orders')
            ->whereNotNull('user_id')
            ->select('user_id')
            ->groupBy('user_id')
            ->orderByRaw('count(*) desc')
            ->limit(1)
            ->value('user_id');

        if ($busiestSeller === null || $contestedProduct === null || $busiestCustomer === null) {
            throw new RuntimeException('The database has no orders, offers or customers. Seed it first.');
        }

        $this->fixtures = [
            'seller_account_id' => (int) $busiestSeller,
            'product_id' => (int) $contestedProduct,
            'product_slug' => (string) DB::table('products')->where('id', $contestedProduct)->value('slug'),
            'user_id' => (int) $busiestCustomer,
            'store_slug' => (string) DB::table('stores')
                ->join('seller_accounts', 'seller_accounts.id', '=', 'stores.seller_account_id')
                ->where('stores.seller_account_id', $busiestSeller)
                ->value('stores.slug'),
            'seller_order_id' => (int) DB::table('seller_orders')
                ->where('seller_account_id', $busiestSeller)
                ->orderByDesc('id')
                ->value('id'),
            'payout_request_id' => (int) DB::table('payout_requests')
                ->orderByDesc('id')
                ->value('id'),
            /*
             * A phrase that matches almost nothing, which is what most
             * real search traffic looks like. The popular-term case is
             * measured too, but a term matching a tenth of the catalogue
             * is one PostgreSQL is right to answer with a scan — judging
             * the search indexes on it would be judging them on the one
             * query they cannot help.
             */
            'long_tail_phrase' => (string) DB::table('product_search_documents')
                ->where('is_public', true)
                ->orderBy('product_id')
                ->selectRaw("split_part(title, ' ', 4) as phrase")
                ->value('phrase'),
            'category_id' => (int) DB::table('products')
                ->select('category_id')
                ->groupBy('category_id')
                ->orderByRaw('count(*) desc')
                ->limit(1)
                ->value('category_id'),
        ];
    }

    /** @return array<int, array{group: string, name: string, run: Closure}> */
    private function discovery(): array
    {
        return [
            [
                'group' => 'discovery',
                'name' => 'search: free-text phrase',
                'run' => fn () => app(BuildDiscoveryPage::class)(
                    app(SearchQueryFactory::class)(Request::create('/search', 'GET', ['q' => 'titanium lantern'])),
                ),
            ],
            [
                'group' => 'discovery',
                'name' => 'search: phrase, in stock, cheapest first',
                'run' => fn () => app(BuildDiscoveryPage::class)(
                    app(SearchQueryFactory::class)(Request::create('/search', 'GET', [
                        'q' => 'kettle',
                        'in_stock' => '1',
                        'sort' => 'price_asc',
                    ])),
                ),
            ],
            [
                'group' => 'discovery',
                'name' => 'search: deep page (page 40)',
                'run' => fn () => app(BuildDiscoveryPage::class)(
                    app(SearchQueryFactory::class)(Request::create('/search', 'GET', ['q' => 'kettle', 'page' => '40'])),
                ),
            ],
            [
                'group' => 'discovery',
                'name' => 'search: long-tail phrase',
                'run' => fn () => app(BuildDiscoveryPage::class)(
                    app(SearchQueryFactory::class)(Request::create('/search', 'GET', [
                        'q' => (string) $this->fixtures['long_tail_phrase'],
                    ])),
                ),
            ],
            [
                'group' => 'discovery',
                'name' => 'category listing: largest category',
                'run' => fn () => app(BuildDiscoveryPage::class)(
                    app(SearchQueryFactory::class)(Request::create('/c', 'GET', [
                        'category' => (string) $this->fixtures['category_id'],
                    ])),
                ),
            ],
            [
                'group' => 'discovery',
                'name' => 'category listing: filtered by price band',
                'run' => fn () => app(BuildDiscoveryPage::class)(
                    app(SearchQueryFactory::class)(Request::create('/c', 'GET', [
                        'category' => (string) $this->fixtures['category_id'],
                        'min_price' => '20',
                        'max_price' => '200',
                        'in_stock' => '1',
                    ])),
                ),
            ],
            [
                'group' => 'discovery',
                'name' => 'product page: most contested product',
                'run' => function (): void {
                    $product = app(FindPublicProduct::class)((string) $this->fixtures['product_slug']);

                    if ($product !== null) {
                        app(BuildProductPage::class)($product);
                    }
                },
            ],
            [
                'group' => 'discovery',
                'name' => 'product reviews: first page',
                'run' => fn () => app(GetProductReviews::class)((int) $this->fixtures['product_id']),
            ],
            [
                'group' => 'discovery',
                'name' => 'store page: busiest store',
                'run' => fn () => app(FindPublicStore::class)((string) $this->fixtures['store_slug']),
            ],
            [
                'group' => 'discovery',
                'name' => 'recommendations: popular in 30 days',
                'run' => fn () => app(GetPopularProducts::class)(30, 12),
            ],
            [
                'group' => 'discovery',
                'name' => 'recommendations: bought together',
                'run' => fn () => app(GetProductAssociations::class)(
                    (int) $this->fixtures['product_id'],
                    AssociationKind::BoughtTogether,
                    8,
                ),
            ],
            [
                'group' => 'discovery',
                'name' => 'sitemap: every indexable url',
                'run' => fn () => app(SitemapUrls::class)->products(),
            ],
        ];
    }

    /** @return array<int, array{group: string, name: string, run: Closure}> */
    private function sellerPortal(): array
    {
        $seller = (int) $this->fixtures['seller_account_id'];

        return [
            [
                'group' => 'seller',
                'name' => 'dashboard: recent orders',
                'run' => fn () => CurrentSeller::actingAs($seller, fn () => app(RecentSellerOrders::class)(10)),
            ],
            [
                'group' => 'seller',
                'name' => 'inventory: first page',
                'run' => fn () => CurrentSeller::actingAs(
                    $seller,
                    fn () => app(InventoryRows::class)(Request::create('/seller/inventory', 'GET'), $seller),
                ),
            ],
            [
                'group' => 'seller',
                'name' => 'inventory: low stock filter',
                'run' => fn () => CurrentSeller::actingAs(
                    $seller,
                    fn () => app(InventoryRows::class)(
                        Request::create('/seller/inventory', 'GET', ['state' => 'low_stock']),
                        $seller,
                    ),
                ),
            ],
            [
                'group' => 'seller',
                'name' => 'fulfilment: one order with history',
                'run' => function () use ($seller): void {
                    CurrentSeller::actingAs($seller, function (): void {
                        $order = SellerOrder::query()->find((int) $this->fixtures['seller_order_id']);

                        if ($order !== null) {
                            app(BuildFulfilmentView::class)->forSellerOrder($order, withHistory: true);
                        }
                    });
                },
            ],
            [
                'group' => 'seller',
                'name' => 'finance: authoritative position',
                'run' => fn () => app(GetSellerFinancialPosition::class)($seller),
            ],
            [
                'group' => 'seller',
                'name' => 'finance: earnings statement',
                'run' => fn () => app(BuildSellerStatement::class)($seller),
            ],
            [
                'group' => 'seller',
                'name' => 'analytics: last 30 days',
                'run' => fn () => app(GetSellerAnalytics::class)($seller, AnalyticsPeriod::Last30Days, 'USD'),
            ],
        ];
    }

    /** @return array<int, array{group: string, name: string, run: Closure}> */
    private function adminConsole(): array
    {
        return [
            [
                'group' => 'admin',
                'name' => 'moderation: product queue',
                'run' => fn () => app(ProductModerationQueue::class)(Request::create('/admin/catalogue/moderation', 'GET')),
            ],
            [
                'group' => 'admin',
                'name' => 'moderation: review queue',
                'run' => fn () => app(BuildModerationQueue::class)(null, null),
            ],
            [
                'group' => 'admin',
                'name' => 'moderation: review queue counts',
                'run' => fn () => app(BuildModerationQueue::class)->counts(),
            ],
            [
                'group' => 'admin',
                'name' => 'sellers: application queue',
                'run' => fn () => app(SellerApplicationQueue::class)(null, null),
            ],
            [
                'group' => 'admin',
                'name' => 'finance: platform summary',
                'run' => fn () => app(SummarisePlatformFinance::class)(),
            ],
            [
                'group' => 'admin',
                'name' => 'finance: positions for every seller',
                'run' => function (): void {
                    /** @var array<int, int> $ids */
                    $ids = DB::table('seller_accounts')->pluck('id')->map(intval(...))->all();
                    app(GetSellerFinancialPosition::class)->forSellers($ids);
                },
            ],
            [
                'group' => 'admin',
                'name' => 'payouts: one request in detail',
                'run' => function (): void {
                    $request = PayoutRequest::query()->find((int) $this->fixtures['payout_request_id']);

                    if ($request !== null) {
                        app(BuildPayoutView::class)->detail($request, withSensitive: true);
                    }
                },
            ],
            [
                'group' => 'admin',
                'name' => 'search health: last 30 days',
                'run' => fn () => app(SearchHealth::class)(30),
            ],
            [
                'group' => 'admin',
                'name' => 'analytics: marketplace, last 30 days',
                'run' => fn () => app(GetMarketplaceAnalytics::class)(AnalyticsPeriod::Last30Days, 'USD'),
            ],
        ];
    }

    /** @return array<int, array{group: string, name: string, run: Closure}> */
    private function customerAccount(): array
    {
        return [
            [
                'group' => 'customer',
                'name' => 'wishlist',
                'run' => fn () => app(GetWishlist::class)((int) $this->fixtures['user_id']),
            ],
            /*
             * The two surfaces that read `interaction_events` directly.
             * Everything else reads the daily rollups, so without these
             * the largest table in the database — two hundred thousand
             * rows, written on every page view — would be measured for
             * its writes and never for its reads.
             */
            [
                'group' => 'customer',
                'name' => 'recommendations: recently viewed',
                'run' => fn () => app(RecentlyViewedStrategy::class)->candidates(new RecommendationRequest(
                    slot: RecommendationSlot::RecentlyViewed,
                    userId: (int) $this->fixtures['user_id'],
                )),
            ],
            [
                'group' => 'customer',
                'name' => 'recommendations: personal affinity',
                'run' => fn () => app(PersonalAffinityStrategy::class)->candidates(new RecommendationRequest(
                    slot: RecommendationSlot::ForYou,
                    userId: (int) $this->fixtures['user_id'],
                )),
            ],
        ];
    }
}
