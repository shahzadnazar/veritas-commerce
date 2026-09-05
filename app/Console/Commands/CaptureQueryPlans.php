<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Diagnostics\DestructiveDatabaseGuard;
use App\Support\Performance\QueryPlanProbe;
use App\Support\Performance\QueryPlanReport;
use App\Support\Performance\ReadSurfaces;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Measure every read surface against a database that is big enough to
 * tell the truth, and write down what PostgreSQL did.
 *
 * Read-only, but not harmless: `EXPLAIN (ANALYZE)` runs the statement it
 * is given, and running the sitemap query and the whole-marketplace
 * analytics rollup twice each is not something to do to a live database
 * while customers are on it. So it refuses a production environment, the
 * same way the seeder does.
 *
 * The output is a markdown report with a row per surface and a section
 * for the findings — sequential scans over large tables, and nodes where
 * the planner's estimate was an order of magnitude out. What it
 * deliberately does not contain is plan text: that changes with the
 * PostgreSQL version and the statistics, so a committed copy of it would
 * be stale before it was reviewed.
 */
final class CaptureQueryPlans extends Command
{
    protected $signature = 'veritas:query-plans
        {--out= : Write the markdown report to this path instead of stdout}
        {--group= : Only measure surfaces in this group}
        {--detail= : Print every statement of surfaces whose name contains this text}';

    protected $description = 'Run every read surface under EXPLAIN ANALYZE and report what the planner did';

    public function handle(ReadSurfaces $surfaces): int
    {
        $guard = DestructiveDatabaseGuard::forCurrentRequest();

        if ($guard->isProductionEnvironment()) {
            $this->error('EXPLAIN ANALYZE executes what it measures. This does not run against production.');

            return self::FAILURE;
        }

        $probe = new QueryPlanProbe(DB::connection());
        $sizes = $probe->tableSizes();

        if (($sizes['products'] ?? 0) < 1_000) {
            $this->warn(sprintf(
                'This database holds about %d products. Plans taken at that size describe a database that will '
                .'never exist — PostgreSQL scans a small table sequentially however good the index is. '
                .'Run veritas:seed-performance first.',
                $sizes['products'] ?? 0,
            ));
        }

        $only = $this->option('group');
        $reports = [];

        foreach ($surfaces->all() as $surface) {
            if ($only !== null && $surface['group'] !== $only) {
                continue;
            }

            $this->line(sprintf('  <fg=gray>%s</> %s', $surface['group'], $surface['name']));

            try {
                $report = $probe->measure($surface['group'], $surface['name'], $surface['run']);
                $reports[] = $report;
                $this->detail($report);
            } catch (Throwable $e) {
                $this->error(sprintf('    %s failed: %s', $surface['name'], $e->getMessage()));

                return self::FAILURE;
            }
        }

        $markdown = $this->render($reports, $sizes, $surfaces->fixtures());
        $out = $this->option('out');

        if (is_string($out) && $out !== '') {
            file_put_contents($out, $markdown);
            $this->newLine();
            $this->info(sprintf('%d surfaces measured. Written to %s.', count($reports), $out));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line($markdown);

        return self::SUCCESS;
    }

    /**
     * Statement-by-statement output for one surface.
     *
     * The summary table answers "is anything slow"; this answers "why",
     * which is the question that has to be settled before an index is
     * added. Printed rather than written to the report, because it is
     * working material — it names the plan nodes of a particular run,
     * and pinning that in a committed document would be pinning
     * something that changes with the statistics.
     */
    private function detail(QueryPlanReport $report): void
    {
        $filter = $this->option('detail');

        if (! is_string($filter) || $filter === '' || ! str_contains($report->name, $filter)) {
            return;
        }

        foreach ($report->statements as $statement) {
            $this->newLine();
            $this->line(sprintf(
                '    <fg=yellow>%.2f ms</> %d rows, %d pages hit, %d read',
                $statement['ms'],
                $statement['rows'],
                $statement['shared_hit'],
                $statement['shared_read'],
            ));
            $this->line('    <fg=gray>'.$this->shorten($statement['sql']).'</>');

            foreach ($statement['nodes'] as $node) {
                $this->line(sprintf(
                    '      %-22s %-32s est %-9s act %-9s x%d',
                    $node['node'],
                    $node['index'] ?? $node['relation'] ?? '',
                    number_format($node['estimated_rows']),
                    number_format($node['actual_rows']),
                    $node['loops'],
                ));
            }
        }

        $this->newLine();
    }

    /**
     * The server's own version string, trimmed to the part that matters.
     *
     * Recorded because a plan is a statement about a particular planner:
     * the same query on a different major version can choose differently,
     * and a report that did not say which one would be evidence for
     * nothing in particular.
     */
    private function serverVersion(): string
    {
        $version = (string) DB::connection()->scalar('show server_version');

        return $version === '' ? 'unknown' : $version;
    }

    private function setting(string $name): string
    {
        return (string) DB::connection()->scalar("select current_setting('{$name}')");
    }

    private function shorten(string $sql): string
    {
        $collapsed = (string) preg_replace('/\s+/', ' ', $sql);

        return mb_strlen($collapsed) > 400 ? mb_substr($collapsed, 0, 400).' …' : $collapsed;
    }

    /**
     * @param  array<int, QueryPlanReport>  $reports
     * @param  array<string, int>  $sizes
     * @param  array<string, int|string>  $fixtures
     */
    private function render(array $reports, array $sizes, array $fixtures): string
    {
        $lines = [
            '# Read-surface query plans',
            '',
            'Generated by `php artisan veritas:query-plans`. Every figure is measured',
            '`EXPLAIN (ANALYZE, BUFFERS)` execution against the dataset described below, not a',
            'planner cost estimate.',
            '',
            'This is a record of one run on one machine, so the milliseconds are not a promise —',
            'they are evidence, and what makes them comparable is that every row was measured the',
            'same way in the same session. Read the access methods and the page counts first; they',
            'survive a change of hardware, and the timings do not. Regenerate with the command',
            'above after any schema or query change that could move a plan.',
            '',
            sprintf('- Captured: %s', now()->toDateString()),
            sprintf('- PostgreSQL: %s', $this->serverVersion()),
            sprintf(
                '- Planner: random_page_cost=%s, work_mem=%s, effective_cache_size=%s',
                $this->setting('random_page_cost'),
                $this->setting('work_mem'),
                $this->setting('effective_cache_size'),
            ),
            '',
            '## Dataset',
            '',
            '| Table | Rows (planner estimate) |',
            '| --- | ---: |',
        ];

        $interesting = ['products', 'offers', 'marketplace_orders', 'seller_orders', 'order_items',
            'seller_ledger_entries', 'product_search_documents', 'interaction_events', 'product_reviews'];

        foreach ($interesting as $table) {
            $lines[] = sprintf('| `%s` | %s |', $table, number_format($sizes[$table] ?? 0));
        }

        $lines[] = '';
        $lines[] = 'Measured against the heaviest row of each kind: '.implode(', ', array_map(
            static fn (int|string $value, string $key): string => "{$key}={$value}",
            $fixtures,
            array_keys($fixtures),
        )).'.';
        $lines[] = '';
        $lines[] = '## Surfaces';
        $lines[] = '';
        $lines[] = '| Surface | Queries | Total ms | Slowest ms | Rows read | Pages hit | Pages read |';
        $lines[] = '| --- | ---: | ---: | ---: | ---: | ---: | ---: |';

        $group = null;

        foreach ($reports as $report) {
            if ($report->group !== $group) {
                $group = $report->group;
                $lines[] = sprintf('| **%s** | | | | | | |', $group);
            }

            $slowest = $report->slowest();

            $lines[] = sprintf(
                '| %s | %d | %.2f | %.2f | %s | %s | %s |',
                $report->name,
                $report->queryCount,
                $report->totalMs,
                (float) ($slowest['ms'] ?? 0),
                number_format((int) ($slowest['rows'] ?? 0)),
                number_format((int) ($slowest['shared_hit'] ?? 0)),
                number_format((int) ($slowest['shared_read'] ?? 0)),
            );
        }

        $lines[] = '';
        $lines[] = '## Sequential scans over large tables';
        $lines[] = '';
        $lines[] = sprintf(
            'A sequential scan is only reported here when the relation holds at least %s rows. Below that, '
            .'reading the table end to end is genuinely cheaper than descending an index and PostgreSQL is right '
            .'to do it.',
            number_format(QueryPlanProbe::SEQUENTIAL_SCAN_THRESHOLD),
        );
        $lines[] = '';

        $scans = [];

        foreach ($reports as $report) {
            foreach ($report->significantSequentialScans($sizes, QueryPlanProbe::SEQUENTIAL_SCAN_THRESHOLD) as $scan) {
                $scans[] = sprintf(
                    '| %s | `%s` | %s | %d | %s |',
                    $report->name,
                    $scan['relation'],
                    number_format($scan['rows']),
                    $scan['loops'],
                    number_format($scan['table_rows']),
                );
            }
        }

        if ($scans === []) {
            $lines[] = 'None.';
        } else {
            $lines[] = '| Surface | Relation | Rows out | Loops | Table rows |';
            $lines[] = '| --- | --- | ---: | ---: | ---: |';
            $lines = array_merge($lines, $scans);
        }

        $lines[] = '';
        $lines[] = '## Row-estimate misses';
        $lines[] = '';
        $lines[] = 'Scan and join nodes that returned at least 10x more rows than the planner predicted.';
        $lines[] = 'Underestimates only: an overestimate usually means a `LIMIT` stopped a scan early, which is';
        $lines[] = 'the plan working. An underestimate is what makes the planner pick a nested loop for three';
        $lines[] = 'rows and then run it thirty thousand times — the shape of most queries that were fast in';
        $lines[] = 'staging and timed out in production.';
        $lines[] = '';

        $misses = [];

        foreach ($reports as $report) {
            foreach ($report->misestimates() as $miss) {
                $misses[] = sprintf(
                    '| %s | %s | `%s` | %s | %s |',
                    $report->name,
                    $miss['node'],
                    $miss['relation'] ?? '—',
                    number_format($miss['estimated']),
                    number_format($miss['actual']),
                );
            }
        }

        if ($misses === []) {
            $lines[] = 'None.';
        } else {
            $lines[] = '| Surface | Node | Relation | Estimated | Actual |';
            $lines[] = '| --- | --- | --- | ---: | ---: |';
            $lines = array_merge($lines, $misses);
        }

        return implode("\n", $lines)."\n";
    }
}
