<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Actions\AdjustInventory;
use App\Modules\Offers\Models\Offer;
use App\Modules\Reviews\Actions\RecomputeRatingSummary;
use App\Modules\Reviews\Models\ProductReview;
use App\Modules\Sellers\Models\SellerAccount;
use App\Modules\Stores\Models\Store;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * A small, known catalogue for smoke-testing a running deployment.
 *
 * The CI Docker job used to build this with a `tinker --execute` heredoc
 * whose output was piped through `tr`, which discarded the exit status —
 * a seeding failure became a confusing curl error three steps later
 * instead of a failed step. A command exits properly, can be tested, and
 * prints exactly the slugs the smoke needs.
 *
 * Refuses to run in production: it writes fictional sellers and offers.
 */
final class SeedDemoCatalogue extends Command
{
    protected $signature = 'veritas:seed-demo-catalogue
        {--title=Aeris Cordless Kettle : Title for the published product}
        {--offers=1 : How many sellers list it}
        {--reviews=3 : How many published reviews to give it}';

    protected $description = 'Create one published product with eligible offers, for smoke tests';

    public function __construct(private readonly AdjustInventory $stock)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('This writes fictional sellers and offers; it will not run in production.');

            return self::FAILURE;
        }

        $title = (string) $this->option('title');
        $offers = max(1, (int) $this->option('offers'));
        $reviews = max(0, (int) $this->option('reviews'));

        /** @var array{product: Product, category: Category, store: Store} $seeded */
        $seeded = DB::transaction(function () use ($title, $offers, $reviews): array {
            $category = Category::factory()->createOne(['name' => 'Kettles', 'is_visible' => true]);

            $product = Product::factory()->createOne([
                'title' => $title,
                'category_id' => $category->id,
            ]);

            $firstStore = null;

            foreach (range(1, $offers) as $index) {
                $seller = SellerAccount::factory()->createOne();
                $store = Store::factory()->createOne([
                    'seller_account_id' => $seller->id,
                    'is_open' => true,
                ]);

                $offer = Offer::factory()->createOne([
                    'seller_account_id' => $seller->id,
                    'store_id' => $store->id,
                    'product_id' => $product->id,
                    'product_variant_id' => null,
                    // Ascending, so the cheapest is predictable and a
                    // smoke test can assert which price is displayed.
                    'price_minor' => 9_900 + ($index - 1) * 1_000,
                ]);

                // Stocked through the ledger, so the smoke exercises the
                // in-stock path — and so `inventory:reconcile` passes
                // against what this leaves behind.
                $this->stock->openingStock($offer, 25, 'system', 0);

                $firstStore ??= $store;
            }

            // Returned rather than read back through relations: these are
            // the rows just written, so there is nothing to re-resolve and
            // no nullable relation to defend against.
            /*
             * Reviews at more than one star, so the smoke test exercises
             * the rating histogram rather than skipping it.
             *
             * The product page renders no histogram at all for a product
             * with no rating, which is how a defect that threw on every
             * rated product passed a CI step whose whole purpose was to
             * prove the page server-renders. A fixture that cannot reach
             * the code under test is not a fixture.
             */
            foreach (array_slice([5, 4, 5, 3, 2], 0, $reviews) as $rating) {
                ProductReview::factory()->rated($rating)->createOne(['product_id' => $product->id]);
            }

            return ['product' => $product, 'category' => $category, 'store' => $firstStore];
        });

        if ($reviews > 0) {
            app(RecomputeRatingSummary::class)((int) $seeded['product']->id);
        }

        // One line each, machine-readable: the smoke script reads these.
        $this->line('product='.$seeded['product']->slug);
        $this->line('category='.$seeded['category']->slug);
        $this->line('store='.$seeded['store']->slug);

        return self::SUCCESS;
    }
}
