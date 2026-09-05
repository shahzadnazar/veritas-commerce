<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Console\Commands\RebuildAnalyticsCommand;
use App\Console\Commands\RebuildRecommendationsCommand;
use App\Modules\Analytics\Actions\RebuildDailyMetrics;
use App\Modules\Analytics\Enums\AnalyticsPeriod;
use App\Modules\Analytics\Queries\GetMarketplaceAnalytics;
use App\Modules\Analytics\Queries\GetSearchAnalytics;
use App\Modules\Analytics\Queries\GetSellerAnalytics;
use App\Modules\Analytics\Support\AnalyticsDay;
use App\Modules\Analytics\Support\SearchPhrase;
use App\Modules\Catalog\Models\Product;
use App\Modules\Commission\Models\CommissionRule;
use App\Modules\Events\Enums\InteractionEventType;
use App\Modules\Identity\Models\User;
use App\Modules\Payouts\Queries\SummarisePlatformFinance;
use App\Modules\Sellers\Models\SellerAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsCommerceFixtures;
use Tests\Feature\Orders\BuildsPlacedOrders;
use Tests\Feature\Payments\BuildsPayableOrders;
use Tests\Feature\Recommendations\BuildsRecommendationFixtures;
use Tests\TestCase;

/**
 * The analytics rollups agree with the transactional truth they are
 * derived from, and cannot alter it.
 *
 * The heart of the file is
 * `daily_money_columns_reconcile_with_the_m7_finance_summary`: it sums
 * every daily row over a period and asserts the totals equal what
 * SummarisePlatformFinance reports for the same period. §56 says the M7
 * definitions remain authoritative for GMV, net sales, commission and
 * seller earnings; this is what stops that being a comment.
 */
final class AnalyticsProjectionTest extends TestCase
{
    use BuildsCommerceFixtures;
    use BuildsPayableOrders;
    use BuildsPlacedOrders;
    use BuildsRecommendationFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CommissionRule::factory()->create(['rate_percent' => '12.00']);
    }

    // ---------------------------------------------------------------
    // §56: the money agrees with M7.
    // ---------------------------------------------------------------

    #[Test]
    public function daily_money_columns_reconcile_with_the_m7_finance_summary(): void
    {
        $this->tradingDay();

        $days = AnalyticsDay::lastDays(3);
        app(RebuildDailyMetrics::class)->forDays($days);

        $rolled = DB::table('daily_marketplace_metrics')
            ->where('currency', 'USD')
            ->selectRaw(
                'coalesce(sum(gmv_minor), 0) as gmv, '.
                'coalesce(sum(refunds_minor), 0) as refunds, '.
                'coalesce(sum(commission_minor), 0) as commission'
            )
            ->first();

        $this->assertNotNull($rolled);

        $summary = app(SummarisePlatformFinance::class)(
            Carbon::instance($days[0]->startsAt->toDateTime()),
            Carbon::instance($days[count($days) - 1]->endsAt->toDateTime()),
            'USD',
        );

        $this->assertGreaterThan(0, (int) $rolled->gmv, 'The fixture should have produced revenue.');
        $this->assertSame((int) $summary['flows']['gmvMinor'], (int) $rolled->gmv, 'GMV disagrees with M7.');
        $this->assertSame((int) $summary['flows']['refundsMinor'], (int) $rolled->refunds, 'Refunds disagree with M7.');
        $this->assertSame(
            (int) $summary['flows']['commissionMinor'],
            (int) $rolled->commission,
            'Platform commission disagrees with M7.',
        );
        $this->assertSame(
            (int) $summary['flows']['netSalesMinor'],
            (int) $rolled->gmv - (int) $rolled->refunds,
            'Net sales must be GMV less refunds, exactly as M7 defines it.',
        );
    }

    #[Test]
    public function seller_earnings_are_copied_from_the_ledger_not_recomputed(): void
    {
        ['seller' => $seller] = $this->tradingDay();

        app(RebuildDailyMetrics::class)->forDays(AnalyticsDay::lastDays(3));

        $projected = (int) DB::table('daily_seller_metrics')
            ->where('seller_account_id', $seller->id)
            ->sum('earnings_minor');

        $ledger = (int) DB::table('seller_ledger_entries')
            ->where('seller_account_id', $seller->id)
            ->whereIn('type', ['sale_earning', 'refund_reversal', 'adjustment'])
            ->sum('amount_minor');

        $this->assertGreaterThan(0, $ledger, 'The fixture should have credited the seller.');
        $this->assertSame($ledger, $projected);
    }

    #[Test]
    public function a_purchase_event_that_never_happened_does_not_move_the_money(): void
    {
        ['product' => $product] = $this->tradingDay();

        app(RebuildDailyMetrics::class)->forDays(AnalyticsDay::lastDays(2));

        $before = (int) DB::table('daily_marketplace_metrics')->sum('gmv_minor');

        // Fifty purchase events with no payment behind them. §48: money
        // is never calculated from behaviour.
        foreach (range(1, 50) as $index) {
            DB::table('interaction_events')->insert([
                'event_id' => (string) Str::ulid(),
                'anonymous_session_id' => 'ghost-'.$index,
                'event_type' => InteractionEventType::PurchaseCompleted->value,
                'product_id' => $product->id,
                'value_minor' => 999_999,
                'created_at' => now(),
            ]);
        }

        app(RebuildDailyMetrics::class)->forDays(AnalyticsDay::lastDays(2));

        $this->assertSame(
            $before,
            (int) DB::table('daily_marketplace_metrics')->sum('gmv_minor'),
            'A clickstream event moved a financial figure.',
        );
    }

    // ---------------------------------------------------------------
    // Idempotency and the boundary.
    // ---------------------------------------------------------------

    #[Test]
    public function rebuilding_the_same_day_twice_produces_identical_rows(): void
    {
        $this->tradingDay();

        Artisan::call('analytics:rebuild', ['--days' => 3]);
        $first = $this->snapshot();

        Artisan::call('analytics:rebuild', ['--days' => 3]);
        $second = $this->snapshot();

        $this->assertNotSame([], $first['marketplace']);
        $this->assertSame($first, $second);
    }

    #[Test]
    public function rebuilding_one_day_leaves_the_others_alone(): void
    {
        $this->tradingDay();

        $days = AnalyticsDay::lastDays(3);
        app(RebuildDailyMetrics::class)->forDays($days);

        $untouched = DB::table('daily_marketplace_metrics')
            ->where('day', $days[0]->date)
            ->get()
            ->map(static fn ($row): array => array_diff_key((array) $row, ['id' => 0, 'computed_at' => 0]))
            ->all();

        app(RebuildDailyMetrics::class)($days[count($days) - 1]);

        $this->assertSame(
            $untouched,
            DB::table('daily_marketplace_metrics')
                ->where('day', $days[0]->date)
                ->get()
                ->map(static fn ($row): array => array_diff_key((array) $row, ['id' => 0, 'computed_at' => 0]))
                ->all(),
        );
    }

    #[Test]
    public function the_rebuild_never_alters_transactional_or_financial_data(): void
    {
        $this->tradingDay();

        $before = $this->transactionalSnapshot();

        $exit = Artisan::call('analytics:rebuild', ['--days' => 3, '--verify' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('no transactional or financial table changed', $output);
        $this->assertSame($before, $this->transactionalSnapshot());
    }

    #[Test]
    public function the_rebuild_writes_only_the_four_daily_tables(): void
    {
        $this->tradingDay();

        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            $sql = strtolower($query->sql);

            if (str_starts_with($sql, 'insert') || str_starts_with($sql, 'update') || str_starts_with($sql, 'delete')) {
                $writes[] = $sql;
            }
        });

        Artisan::call('analytics:rebuild', ['--days' => 2]);

        $this->assertNotSame([], $writes);

        foreach ($writes as $sql) {
            $this->assertMatchesRegularExpression(
                '/daily_(marketplace|product|seller|search)_metrics/',
                $sql,
                "The analytics rebuild wrote outside its own projections: {$sql}",
            );
        }
    }

    #[Test]
    public function the_two_rebuild_commands_protect_the_same_tables(): void
    {
        $this->assertSame(
            RebuildRecommendationsCommand::PROTECTED_TABLES,
            RebuildAnalyticsCommand::PROTECTED_TABLES,
            'One list of what the insight layer may not touch, not two that drift.',
        );
    }

    // ---------------------------------------------------------------
    // Day boundaries.
    // ---------------------------------------------------------------

    #[Test]
    public function a_day_runs_from_local_midnight_to_local_midnight(): void
    {
        config(['veritas.identity.timezone' => 'America/Los_Angeles']);

        $day = AnalyticsDay::of('2026-03-03');

        $this->assertSame('2026-03-03', $day->date);
        $this->assertSame('2026-03-03T08:00:00+00:00', $day->startsAt->toIso8601String());
        $this->assertSame('2026-03-04T08:00:00+00:00', $day->endsAt->toIso8601String());
    }

    #[Test]
    public function an_event_just_before_local_midnight_belongs_to_that_day(): void
    {
        config(['veritas.identity.timezone' => 'America/Los_Angeles']);

        $product = $this->listedProduct('Late night')['product'];

        // 23:30 in Los Angeles on the 3rd is 07:30 UTC on the 4th.
        $this->viewed($product, session: 'night-owl', at: '2026-03-04 07:30:00');

        app(RebuildDailyMetrics::class)(AnalyticsDay::of('2026-03-03'));
        app(RebuildDailyMetrics::class)(AnalyticsDay::of('2026-03-04'));

        $this->assertSame(
            1,
            (int) DB::table('daily_marketplace_metrics')->where('day', '2026-03-03')->value('product_views'),
        );
        $this->assertSame(
            0,
            (int) DB::table('daily_marketplace_metrics')->where('day', '2026-03-04')->value('product_views'),
        );
    }

    // ---------------------------------------------------------------
    // Search.
    // ---------------------------------------------------------------

    #[Test]
    public function search_phrases_are_normalised_into_one_row(): void
    {
        foreach ([' Kettle ', 'KETTLE', 'kettle', 'kettle  cordless'] as $index => $phrase) {
            $this->search($phrase, resultCount: 4, session: 'shopper-'.$index);
        }

        app(RebuildDailyMetrics::class)(AnalyticsDay::today());

        $rows = DB::table('daily_search_metrics')
            ->orderBy('query_normalised')
            ->get(['query_normalised', 'searches'])
            ->map(static fn ($row): array => (array) $row)
            ->all();

        $this->assertCount(2, $rows, 'Three spellings of one word are one row.');
        $this->assertSame('kettle', (string) $rows[0]['query_normalised']);
        $this->assertSame(3, (int) $rows[0]['searches']);
        $this->assertSame('kettle cordless', (string) $rows[1]['query_normalised']);
    }

    #[Test]
    public function a_phrase_that_never_finds_anything_is_listed_separately(): void
    {
        $this->search('espresso machine', resultCount: 0, session: 'a');
        $this->search('espresso machine', resultCount: 0, session: 'b');
        $this->search('kettle', resultCount: 6, session: 'c');
        // A phrase that usually works and failed once is not a gap.
        $this->search('kettle', resultCount: 0, session: 'd');

        app(RebuildDailyMetrics::class)(AnalyticsDay::today());

        $report = app(GetSearchAnalytics::class)(AnalyticsPeriod::Last7Days);
        $phrases = array_column($report['zeroResultPhrases'], 'phrase');

        $this->assertSame(['espresso machine'], $phrases);
        $this->assertSame(4, $report['totals']['searches']);
        $this->assertSame(3, $report['totals']['zeroResults']);
    }

    #[Test]
    public function a_click_is_credited_to_the_phrase_that_preceded_it(): void
    {
        $product = $this->listedProduct('Clicked')['product'];

        $this->search('kettle', resultCount: 3, session: 'shopper', at: '-30 minutes');
        $this->event(InteractionEventType::SearchResultClicked, $product, session: 'shopper');

        app(RebuildDailyMetrics::class)(AnalyticsDay::today());

        $this->assertSame(
            1,
            (int) DB::table('daily_search_metrics')->where('query_normalised', 'kettle')->value('clicks'),
        );
    }

    #[Test]
    public function a_click_with_no_preceding_search_is_credited_to_nobody(): void
    {
        $product = $this->listedProduct('Direct')['product'];

        $this->search('kettle', resultCount: 3, session: 'searcher');
        // A different visitor who clicked without searching.
        $this->event(InteractionEventType::SearchResultClicked, $product, session: 'browser');

        app(RebuildDailyMetrics::class)(AnalyticsDay::today());

        $this->assertSame(
            0,
            (int) DB::table('daily_search_metrics')->where('query_normalised', 'kettle')->value('clicks'),
            'Crediting a phrase the visitor never typed would be an invention.',
        );
    }

    #[Test]
    public function the_phrase_normaliser_is_conservative(): void
    {
        $this->assertSame('kettle', SearchPhrase::normalise('  KETTLE  '));
        $this->assertSame('kettle cordless', SearchPhrase::normalise("kettle\t\n cordless"));
        $this->assertNull(SearchPhrase::normalise('   '));
        // No stemming and no spelling correction: the misspellings are
        // exactly what a catalogue team needs to see.
        $this->assertSame('kettels', SearchPhrase::normalise('Kettels'));
        $this->assertSame(200, mb_strlen((string) SearchPhrase::normalise(str_repeat('a', 400))));
    }

    // ---------------------------------------------------------------
    // Read surfaces.
    // ---------------------------------------------------------------

    #[Test]
    public function the_marketplace_dashboard_fills_days_with_no_rows(): void
    {
        $this->tradingDay();
        app(RebuildDailyMetrics::class)(AnalyticsDay::today());

        $report = app(GetMarketplaceAnalytics::class)(AnalyticsPeriod::Last7Days, 'USD');

        foreach ($report['series'] as $series) {
            $this->assertCount(7, $series['days'], 'A chart must have one point per day.');
            $this->assertCount(7, $series['values']);
        }

        $this->assertSame(7, $report['coverage']['days']);
        $this->assertSame(1, $report['coverage']['computed']);
        $this->assertFalse($report['coverage']['complete']);
        $this->assertCount(6, $report['coverage']['missing']);
    }

    #[Test]
    public function a_seller_dashboard_shows_only_that_sellers_numbers(): void
    {
        ['seller' => $mine, 'offer' => $myOffer] = $this->sellableOffer('Mine', 10_000);
        ['seller' => $theirs, 'offer' => $theirOffer] = $this->sellableOffer('Theirs', 20_000);

        $buyer = User::factory()->create();
        $this->payFor($this->placeOrder([[$myOffer, 1], [$theirOffer, 2]], (int) $buyer->id, $buyer->email));

        app(RebuildDailyMetrics::class)(AnalyticsDay::today());

        $report = app(GetSellerAnalytics::class)((int) $mine->id, AnalyticsPeriod::Last7Days, 'USD');

        $this->assertSame(
            10_000,
            $this->total($report, 'gross_minor')['value'],
            "A seller's gross is their own lines only.",
        );
        $this->assertSame(1, $this->total($report, 'units_sold')['value']);

        $titles = array_column($report['topProducts'], 'title');
        $this->assertSame(['Mine'], $titles, "A rival's product must never appear on a seller's dashboard.");

        $rival = app(GetSellerAnalytics::class)((int) $theirs->id, AnalyticsPeriod::Last7Days, 'USD');
        $this->assertSame(40_000, $this->total($rival, 'gross_minor')['value']);
    }

    #[Test]
    public function a_total_with_no_previous_window_reports_no_change(): void
    {
        $this->tradingDay();
        app(RebuildDailyMetrics::class)->forDays(AnalyticsDay::lastDays(7));

        $report = app(GetMarketplaceAnalytics::class)(AnalyticsPeriod::Last7Days, 'USD');
        $gmv = $this->total($report, 'gmv_minor');

        $this->assertGreaterThan(0, $gmv['value']);
        $this->assertNull(
            $gmv['changePercent'],
            'Growing from nothing is not a percentage, and pretending otherwise is a lie a chart tells.',
        );
    }

    #[Test]
    public function currency_is_a_filter_and_never_a_sum(): void
    {
        $this->tradingDay();
        app(RebuildDailyMetrics::class)(AnalyticsDay::today());

        $usd = app(GetMarketplaceAnalytics::class)(AnalyticsPeriod::Last7Days, 'USD');
        $eur = app(GetMarketplaceAnalytics::class)(AnalyticsPeriod::Last7Days, 'EUR');

        $this->assertSame('USD', $usd['currency']);
        $this->assertSame('EUR', $eur['currency']);
        $this->assertGreaterThan(0, $this->total($usd, 'gmv_minor')['value']);
        $this->assertSame(0, $this->total($eur, 'gmv_minor')['value']);
    }

    // ---------------------------------------------------------------
    // Helpers.
    // ---------------------------------------------------------------

    /**
     * One headline figure from a dashboard payload, by key.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function total(array $report, string $key): array
    {
        $totals = $report['totals'];

        $this->assertIsArray($totals);

        foreach ($totals as $total) {
            if (is_array($total) && ($total['key'] ?? null) === $key) {
                return $total;
            }
        }

        $this->fail("The dashboard payload has no total named {$key}.");
    }

    /** @return array{seller: SellerAccount, product: Product} */
    private function tradingDay(): array
    {
        ['offer' => $offer, 'seller' => $seller, 'product' => $product] = $this->sellableOffer('Kettle', 9_900);

        foreach (range(1, 2) as $index) {
            $buyer = User::factory()->create();
            $this->payFor($this->placeOrder([[$offer, 1]], (int) $buyer->id, $buyer->email));

            $this->viewed($product, session: 'browser-'.$index);
        }

        return ['seller' => $seller, 'product' => $product];
    }

    private function search(string $phrase, int $resultCount, string $session, ?string $at = null): void
    {
        DB::table('interaction_events')->insert([
            'event_id' => (string) Str::ulid(),
            'anonymous_session_id' => $session,
            'event_type' => InteractionEventType::SearchPerformed->value,
            'search_query' => $phrase,
            'metadata' => json_encode(['query' => $phrase, 'results' => $resultCount, 'zero_results' => $resultCount === 0]),
            'created_at' => $at === null ? now() : Carbon::parse($at),
        ]);
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    private function snapshot(): array
    {
        $tables = [
            'marketplace' => 'daily_marketplace_metrics',
            'products' => 'daily_product_metrics',
            'sellers' => 'daily_seller_metrics',
            'searches' => 'daily_search_metrics',
        ];

        $snapshot = [];

        foreach ($tables as $key => $table) {
            $snapshot[$key] = DB::table($table)
                ->orderBy('day')
                ->orderBy('id')
                ->get()
                ->map(static fn ($row): array => array_diff_key((array) $row, ['id' => 0, 'computed_at' => 0]))
                ->all();
        }

        return $snapshot;
    }

    /** @return array<string, string> */
    private function transactionalSnapshot(): array
    {
        $snapshot = [];

        foreach (RebuildAnalyticsCommand::PROTECTED_TABLES as $table) {
            if (! DB::getSchemaBuilder()->hasTable($table)) {
                continue;
            }

            $row = DB::table($table)->selectRaw('count(*) as rows, coalesce(sum(id), 0) as checksum')->first();
            $snapshot[$table] = $row === null ? 'unreadable' : $row->rows.'/'.$row->checksum;
        }

        return $snapshot;
    }
}
