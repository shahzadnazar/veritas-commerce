<?php

declare(strict_types=1);

namespace Tests\Feature\Recommendations;

use App\Console\Commands\RebuildRecommendationsCommand;
use App\Modules\Commission\Models\CommissionRule;
use App\Modules\Events\Enums\InteractionEventType;
use App\Modules\Identity\Models\User;
use App\Modules\Recommendations\Actions\RebuildPopularityScores;
use App\Modules\Recommendations\Actions\RebuildProductAssociations;
use App\Modules\Recommendations\Enums\AssociationKind;
use App\Modules\Recommendations\Enums\PopularitySignal;
use App\Modules\Recommendations\Queries\GetPopularProducts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsCommerceFixtures;
use Tests\Feature\Orders\BuildsPlacedOrders;
use Tests\Feature\Payments\BuildsPayableOrders;
use Tests\TestCase;

/**
 * The rebuild is deterministic, idempotent, and cannot touch anything it
 * does not own.
 *
 * The third of those is the sixth property the M8 brief requires proved
 * before any dashboard exists (§60): an analytics or recommendation
 * rebuild may read orders, payments and the ledger, and may never alter
 * them. It is asserted here by fingerprinting every protected table either
 * side of a run, using the command's own list — so a table added to the
 * financial core and forgotten here is a decision somebody has to make,
 * not an omission that quietly narrows the check.
 */
final class RecommendationRebuildTest extends TestCase
{
    use BuildsCommerceFixtures;
    use BuildsPayableOrders;
    use BuildsPlacedOrders;
    use BuildsRecommendationFixtures;
    use RefreshDatabase;

    private Carbon $asOf;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asOf = Carbon::parse('2026-09-01 12:00:00');

        // Paying for an order needs a commission rate in force.
        CommissionRule::factory()->create(['rate_percent' => '12.00']);
    }

    // ---------------------------------------------------------------
    // Popularity.
    // ---------------------------------------------------------------

    #[Test]
    public function popularity_weights_each_signal_from_configuration(): void
    {
        $product = $this->listedProduct('Weighted')['product'];

        $this->viewed($product, session: 'a', at: '2026-08-31 09:00:00');
        $this->viewed($product, session: 'b', at: '2026-08-31 09:05:00');
        $this->event(InteractionEventType::SearchResultClicked, $product, session: 'a', at: '2026-08-31 09:06:00');
        $this->event(InteractionEventType::CartItemAdded, $product, session: 'a', at: '2026-08-31 09:07:00');
        $this->event(InteractionEventType::PurchaseCompleted, $product, session: 'a', at: '2026-08-31 09:08:00');

        app(RebuildPopularityScores::class)(7, $this->asOf);

        $row = DB::table('product_popularity_scores')
            ->where('product_id', $product->id)
            ->where('window_days', 7)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(2, (int) $row->view_count);
        $this->assertSame(1, (int) $row->search_click_count);
        $this->assertSame(1, (int) $row->cart_count);
        $this->assertSame(1, (int) $row->purchase_count);

        $expected = 2 * PopularitySignal::View->weight()
            + PopularitySignal::SearchClick->weight()
            + PopularitySignal::Cart->weight()
            + PopularitySignal::Purchase->weight();

        $this->assertSame($expected, (int) $row->score);
    }

    #[Test]
    public function one_visitor_refreshing_forty_times_counts_once(): void
    {
        $product = $this->listedProduct('Refreshed')['product'];

        foreach (range(1, 40) as $index) {
            $this->viewed($product, session: 'persistent', at: '2026-08-31 09:00:'.str_pad((string) ($index % 60), 2, '0', STR_PAD_LEFT));
        }

        app(RebuildPopularityScores::class)(7, $this->asOf);

        $this->assertSame(
            1,
            (int) DB::table('product_popularity_scores')
                ->where('product_id', $product->id)
                ->where('window_days', 7)
                ->value('view_count'),
            'Popularity must measure interest, not patience.',
        );
    }

    #[Test]
    public function a_wishlist_save_counts_towards_popularity(): void
    {
        $product = $this->listedProduct('Saved')['product'];
        $user = User::factory()->create();

        DB::table('wishlist_items')->insert([
            'public_id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'product_id' => $product->id,
            'created_at' => '2026-08-30 10:00:00',
        ]);

        app(RebuildPopularityScores::class)(7, $this->asOf);

        $row = DB::table('product_popularity_scores')->where('product_id', $product->id)->first();

        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row->wishlist_count);
        $this->assertSame(PopularitySignal::Wishlist->weight(), (int) $row->score);
    }

    #[Test]
    public function behaviour_outside_the_window_does_not_count(): void
    {
        $product = $this->listedProduct('Old news')['product'];

        $this->viewed($product, session: 'ancient', at: '2026-06-01 09:00:00');

        app(RebuildPopularityScores::class)(7, $this->asOf);

        $this->assertSame(
            0,
            DB::table('product_popularity_scores')->where('product_id', $product->id)->count(),
        );

        app(RebuildPopularityScores::class)(180, $this->asOf);

        $this->assertSame(
            1,
            DB::table('product_popularity_scores')
                ->where('product_id', $product->id)
                ->where('window_days', 180)
                ->count(),
        );
    }

    #[Test]
    public function rebuilding_a_window_replaces_only_that_window(): void
    {
        $product = $this->listedProduct('Both windows')['product'];
        $this->viewed($product, session: 'a', at: '2026-08-31 09:00:00');

        app(RebuildPopularityScores::class)(7, $this->asOf);
        app(RebuildPopularityScores::class)(30, $this->asOf);

        $this->assertSame(2, DB::table('product_popularity_scores')->count());

        app(RebuildPopularityScores::class)(7, $this->asOf);

        $this->assertSame(2, DB::table('product_popularity_scores')->count());
        $this->assertSame(
            1,
            DB::table('product_popularity_scores')->where('window_days', 30)->count(),
        );
    }

    // ---------------------------------------------------------------
    // Associations.
    // ---------------------------------------------------------------

    #[Test]
    public function two_products_viewed_in_one_session_become_a_symmetric_pair(): void
    {
        $left = $this->listedProduct('Left')['product'];
        $right = $this->listedProduct('Right')['product'];

        // Three separate visitors, so the pair clears the threshold.
        foreach (['one', 'two', 'three'] as $session) {
            $this->viewed($left, session: $session, at: '2026-08-30 09:00:00');
            $this->viewed($right, session: $session, at: '2026-08-30 09:01:00');
        }

        app(RebuildProductAssociations::class)($this->asOf);

        $forward = DB::table('product_associations')
            ->where('product_id', $left->id)
            ->where('associated_product_id', $right->id)
            ->where('kind', AssociationKind::ViewedTogether->value)
            ->first();

        $backward = DB::table('product_associations')
            ->where('product_id', $right->id)
            ->where('associated_product_id', $left->id)
            ->where('kind', AssociationKind::ViewedTogether->value)
            ->first();

        $this->assertNotNull($forward);
        $this->assertNotNull($backward);
        $this->assertSame(3, (int) $forward->support);
        $this->assertSame((int) $forward->support, (int) $backward->support);
    }

    #[Test]
    public function only_paid_orders_produce_bought_together_pairs(): void
    {
        ['offer' => $first] = $this->sellableOffer('Kettle', 9_900);
        ['offer' => $second] = $this->sellableOffer('Toaster', 7_900);

        $user = User::factory()->create();

        // Placed but never paid.
        $this->placeOrder([[$first, 1], [$second, 1]], (int) $user->id, $user->email);

        app(RebuildProductAssociations::class)();

        $this->assertSame(
            0,
            DB::table('product_associations')
                ->where('kind', AssociationKind::BoughtTogether->value)
                ->count(),
            'An unpaid basket is an intention, not a purchase.',
        );

        // Two more customers who did pay.
        foreach (range(1, 2) as $ignored) {
            $buyer = User::factory()->create();
            $order = $this->placeOrder([[$first, 1], [$second, 1]], (int) $buyer->id, $buyer->email);
            $this->payFor($order);
        }

        app(RebuildProductAssociations::class)();

        $this->assertSame(
            2,
            (int) DB::table('product_associations')
                ->where('product_id', $first->product_id)
                ->where('associated_product_id', $second->product_id)
                ->where('kind', AssociationKind::BoughtTogether->value)
                ->value('support'),
        );
    }

    #[Test]
    public function a_single_product_basket_produces_no_pairs(): void
    {
        $lonely = $this->listedProduct('Lonely')['product'];

        foreach (['one', 'two', 'three', 'four'] as $session) {
            $this->viewed($lonely, session: $session, at: '2026-08-30 09:00:00');
        }

        app(RebuildProductAssociations::class)($this->asOf);

        $this->assertSame(0, DB::table('product_associations')->count());
    }

    // ---------------------------------------------------------------
    // Determinism, idempotency, and the boundary.
    // ---------------------------------------------------------------

    #[Test]
    public function two_identical_rebuilds_produce_identical_rows(): void
    {
        $this->busyMarketplace();

        Artisan::call('recommendations:rebuild', ['--as-of' => $this->asOf->toDateTimeString()]);
        $first = $this->projectionSnapshot();

        Artisan::call('recommendations:rebuild', ['--as-of' => $this->asOf->toDateTimeString()]);
        $second = $this->projectionSnapshot();

        $this->assertNotSame([], $first['popularity']);
        $this->assertNotSame([], $first['associations']);
        $this->assertSame($first, $second, 'A second rebuild over unchanged data must change nothing.');
    }

    #[Test]
    public function the_rebuild_never_alters_transactional_or_financial_data(): void
    {
        $this->busyMarketplace();

        $before = $this->transactionalSnapshot();

        $exit = Artisan::call('recommendations:rebuild', [
            '--as-of' => $this->asOf->toDateTimeString(),
            '--verify' => true,
        ]);

        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('no transactional or financial table changed', $output);
        $this->assertSame($before, $this->transactionalSnapshot());
    }

    #[Test]
    public function the_protected_table_list_covers_every_financial_table(): void
    {
        $required = [
            'marketplace_orders', 'seller_orders', 'order_items',
            'payments', 'refunds',
            'seller_ledger_entries', 'seller_accounts',
            'payout_requests', 'payout_allocations',
            'inventory_movements',
        ];

        foreach ($required as $table) {
            $this->assertContains(
                $table,
                RebuildRecommendationsCommand::PROTECTED_TABLES,
                "{$table} holds transactional truth and must be fingerprinted by --verify.",
            );
            $this->assertTrue(
                DB::getSchemaBuilder()->hasTable($table),
                "{$table} is listed as protected but does not exist — the list has gone stale.",
            );
        }
    }

    #[Test]
    public function the_rebuild_writes_only_its_own_two_tables(): void
    {
        $this->busyMarketplace();

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $sql = strtolower($query->sql);

            if (str_starts_with($sql, 'insert') || str_starts_with($sql, 'update') || str_starts_with($sql, 'delete')) {
                $queries[] = $sql;
            }
        });

        Artisan::call('recommendations:rebuild', ['--as-of' => $this->asOf->toDateTimeString()]);

        $this->assertNotSame([], $queries, 'The rebuild should have written something.');

        foreach ($queries as $sql) {
            $this->assertTrue(
                str_contains($sql, 'product_popularity_scores') || str_contains($sql, 'product_associations'),
                "The rebuild issued a write outside its own projections: {$sql}",
            );
        }
    }

    #[Test]
    public function pinning_the_window_makes_the_output_reproducible(): void
    {
        $product = $this->listedProduct('Pinned')['product'];
        $this->viewed($product, session: 'a', at: '2026-08-28 09:00:00');

        Artisan::call('recommendations:rebuild', ['--as-of' => '2026-09-01 12:00:00']);
        $inWindow = DB::table('product_popularity_scores')->where('window_days', 7)->count();

        Artisan::call('recommendations:rebuild', ['--as-of' => '2026-11-01 12:00:00']);
        $outOfWindow = DB::table('product_popularity_scores')->where('window_days', 7)->count();

        $this->assertSame(1, $inWindow);
        $this->assertSame(0, $outOfWindow, 'The same data, a later window: the score should have aged out.');
    }

    #[Test]
    public function the_configured_windows_are_the_windows_that_get_built(): void
    {
        $product = $this->listedProduct('Windowed')['product'];
        $this->viewed($product, session: 'a', at: '2026-08-31 09:00:00');

        Artisan::call('recommendations:rebuild', ['--as-of' => $this->asOf->toDateTimeString()]);

        $built = DB::table('product_popularity_scores')
            ->distinct()
            ->orderBy('window_days')
            ->pluck('window_days')
            ->map(intval(...))
            ->all();

        $configured = GetPopularProducts::windows();
        sort($configured);

        $this->assertSame($configured, $built);
    }

    // ---------------------------------------------------------------
    // Helpers.
    // ---------------------------------------------------------------

    /** A marketplace with real orders, payments and browsing behind it. */
    private function busyMarketplace(): void
    {
        ['offer' => $kettle] = $this->sellableOffer('Kettle', 9_900);
        ['offer' => $toaster] = $this->sellableOffer('Toaster', 7_900);

        foreach (range(1, 3) as $index) {
            $buyer = User::factory()->create();
            $order = $this->placeOrder([[$kettle, 1], [$toaster, 1]], (int) $buyer->id, $buyer->email);
            $this->payFor($order);

            DB::table('interaction_events')->insert([
                [
                    'event_id' => (string) Str::ulid(),
                    'anonymous_session_id' => 'browser-'.$index,
                    'event_type' => InteractionEventType::ProductViewed->value,
                    'product_id' => $kettle->product_id,
                    'created_at' => '2026-08-30 09:00:00',
                ],
                [
                    'event_id' => (string) Str::ulid(),
                    'anonymous_session_id' => 'browser-'.$index,
                    'event_type' => InteractionEventType::ProductViewed->value,
                    'product_id' => $toaster->product_id,
                    'created_at' => '2026-08-30 09:01:00',
                ],
            ]);
        }
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    private function projectionSnapshot(): array
    {
        return [
            'popularity' => DB::table('product_popularity_scores')
                ->orderBy('window_days')->orderBy('product_id')
                ->get(['product_id', 'window_days', 'score', 'view_count', 'search_click_count', 'wishlist_count', 'cart_count', 'purchase_count'])
                ->map(static fn ($row): array => (array) $row)
                ->all(),
            'associations' => DB::table('product_associations')
                ->orderBy('kind')->orderBy('product_id')->orderBy('associated_product_id')
                ->get(['product_id', 'associated_product_id', 'kind', 'support', 'score'])
                ->map(static fn ($row): array => (array) $row)
                ->all(),
        ];
    }

    /** @return array<string, string> */
    private function transactionalSnapshot(): array
    {
        $snapshot = [];

        foreach (RebuildRecommendationsCommand::PROTECTED_TABLES as $table) {
            if (! DB::getSchemaBuilder()->hasTable($table)) {
                continue;
            }

            $row = DB::table($table)
                ->selectRaw('count(*) as rows, coalesce(sum(id), 0) as checksum')
                ->first();

            $snapshot[$table] = $row === null ? 'unreadable' : $row->rows.'/'.$row->checksum;
        }

        return $snapshot;
    }
}
