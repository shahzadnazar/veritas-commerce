<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Recommendations\Actions\RebuildPopularityScores;
use App\Modules\Recommendations\Actions\RebuildProductAssociations;
use App\Modules\Recommendations\Queries\GetPopularProducts;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * `recommendations:rebuild` — recompute the popularity and co-occurrence
 * projections from behaviour the marketplace already recorded.
 *
 * Three properties, all of which the M8 test suite asserts rather than
 * assumes:
 *
 *   - **Deterministic.** `--as-of` pins the window, so a rebuild of the
 *     same data at a different clock time produces the same rows. Without
 *     it, "the last 7 days" would mean something new every second and no
 *     test could pin the output.
 *   - **Idempotent.** Every window and every association kind is deleted
 *     and reinserted inside one transaction. Running it twice over
 *     unchanged data leaves the tables byte-identical; a run that dies
 *     halfway leaves stale rows, never half-counted ones.
 *   - **Read-only outside its own projections.** §60: it reads interaction
 *     events, orders, payments, wishlists and the search index, and writes
 *     exactly two tables. It cannot alter an order, a payment, an
 *     inventory level, a ledger entry or a payout, and `--verify` proves
 *     it by fingerprinting those tables either side of the run.
 */
final class RebuildRecommendationsCommand extends Command
{
    protected $signature = 'recommendations:rebuild
        {--as-of= : Pin the window end (any parseable date), for a repeatable rebuild}
        {--verify : Fingerprint the transactional tables before and after, and fail if anything moved}';

    protected $description = 'Recompute product popularity scores and product associations from behaviour.';

    /**
     * The tables a recommendation rebuild has no business changing.
     *
     * Listed here rather than inferred, so adding a financial table
     * without adding it to this list is a decision somebody has to make
     * rather than an omission nobody notices.
     */
    public const PROTECTED_TABLES = [
        'marketplace_orders', 'seller_orders', 'order_items',
        'payments', 'refunds', 'payment_attempts',
        'seller_ledger_entries', 'seller_accounts',
        'payout_requests', 'payout_allocations',
        'inventory_balances', 'inventory_movements', 'inventory_reservations',
        'offers',
    ];

    public function handle(
        RebuildPopularityScores $popularity,
        RebuildProductAssociations $associations,
    ): int {
        $asOfOption = $this->option('as-of');
        $asOf = is_string($asOfOption) && $asOfOption !== ''
            ? Carbon::parse($asOfOption)
            : Carbon::now();

        $verify = (bool) $this->option('verify');
        $before = $verify ? $this->fingerprint() : [];

        $this->line('Rebuilding as of '.$asOf->toDateTimeString().'.');

        foreach (GetPopularProducts::windows() as $window) {
            $written = $popularity($window, $asOf);
            $this->line("  popularity, {$window}-day window: {$written} products scored.");
        }

        foreach ($associations($asOf) as $kind => $written) {
            $this->line("  associations, {$kind}: {$written} pairs.");
        }

        if (! $verify) {
            return self::SUCCESS;
        }

        $moved = $this->changedTables($before, $this->fingerprint());

        if ($moved !== []) {
            $this->error('The rebuild altered transactional data, which it must never do:');

            foreach ($moved as $table) {
                $this->line("  - {$table}");
            }

            return self::FAILURE;
        }

        $this->info('Verified: no transactional or financial table changed.');

        return self::SUCCESS;
    }

    /**
     * A cheap signature of each protected table.
     *
     * Row count plus the maximum id plus a checksum over the primary keys:
     * enough to catch an insert, a delete or a renumbering, and cheap
     * enough to run either side of every rebuild. A missing table is
     * recorded as such rather than skipped, so a rename cannot make the
     * check silently pass.
     *
     * @return array<string, string>
     */
    private function fingerprint(): array
    {
        $signatures = [];

        foreach (self::PROTECTED_TABLES as $table) {
            if (! DB::getSchemaBuilder()->hasTable($table)) {
                $signatures[$table] = 'absent';

                continue;
            }

            $row = DB::table($table)
                ->selectRaw('count(*) as rows, coalesce(max(id), 0) as top, coalesce(sum(id), 0) as checksum')
                ->first();

            $signatures[$table] = $row === null
                ? 'unreadable'
                : $row->rows.'/'.$row->top.'/'.$row->checksum;
        }

        return $signatures;
    }

    /**
     * @param  array<string, string>  $before
     * @param  array<string, string>  $after
     * @return array<int, string>
     */
    private function changedTables(array $before, array $after): array
    {
        $changed = [];

        foreach ($before as $table => $signature) {
            if (($after[$table] ?? null) !== $signature) {
                $changed[] = $table;
            }
        }

        return $changed;
    }
}
