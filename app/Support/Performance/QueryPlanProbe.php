<?php

declare(strict_types=1);

namespace App\Support\Performance;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Run a read surface and ask PostgreSQL what it actually did.
 *
 * The surfaces are exercised through the application's own query objects
 * rather than through hand-written SQL, because the SQL that matters is
 * the SQL Eloquent generates — including the parts nobody wrote on
 * purpose, like the count query a paginator issues or the second query
 * an eager load adds. A benchmark of SQL written for the benchmark
 * measures the benchmark.
 *
 * Every statement the surface issues is re-run under
 * `EXPLAIN (ANALYZE, BUFFERS)`, so the numbers reported are measured
 * execution rather than the planner's opinion of it. That distinction is
 * the whole difference between "this index made the cost estimate go
 * down" and "this index made the query faster".
 */
final class QueryPlanProbe
{
    /** Rows below which a sequential scan is the right answer, not a finding. */
    public const SEQUENTIAL_SCAN_THRESHOLD = 5_000;

    /** @var array<string, int>|null */
    private ?array $tableSizes = null;

    public function __construct(private readonly Connection $db) {}

    /**
     * Measure one surface.
     *
     * The surface runs twice. The first run is discarded: it pays for
     * whatever the process has not yet loaded — a cold buffer cache, a
     * relation whose statistics are not yet in the backend's local cache
     * — and reporting that as the cost of the query would blame the
     * query for the harness. The second run is measured.
     */
    public function measure(string $group, string $name, Closure $surface): QueryPlanReport
    {
        try {
            $surface();
        } catch (Throwable) {
            // Reported on the measured run below, where it can be seen.
        }

        /*
         * The application cache is emptied between the two runs, and
         * PostgreSQL's buffers deliberately are not.
         *
         * Those are opposite decisions about the same word. A surface
         * that memoises — the sitemap does, for ten minutes — would
         * otherwise report zero queries, having measured the warm-up run
         * instead of itself. But the cost that matters is the cold one:
         * the cache expires under load, and every request that arrives in
         * that moment pays it. PostgreSQL's buffer cache is different —
         * a production server's is warm, so measuring against a cold one
         * would report a disk-bound cost nobody will ever pay.
         */
        Cache::flush();

        $this->db->flushQueryLog();
        $this->db->enableQueryLog();

        $surface();

        /** @var array<int, array{query: string, bindings: array<int, mixed>, time: float}> $log */
        $log = $this->db->getQueryLog();
        $this->db->disableQueryLog();

        $statements = [];
        $total = 0.0;

        foreach ($log as $entry) {
            $total += (float) $entry['time'];

            if (! $this->isReadable($entry['query'])) {
                continue;
            }

            $plan = $this->explain($entry['query'], $entry['bindings']);

            if ($plan === null) {
                continue;
            }

            $statements[] = [
                'sql' => $entry['query'],
                'ms' => (float) $entry['time'],
                'rows' => $plan['rows'],
                'planning_ms' => $plan['planning_ms'],
                'execution_ms' => $plan['execution_ms'],
                'shared_hit' => $plan['shared_hit'],
                'shared_read' => $plan['shared_read'],
                'nodes' => $plan['nodes'],
            ];
        }

        return new QueryPlanReport($group, $name, count($log), round($total, 2), $statements);
    }

    /**
     * How many rows each table holds, for judging a sequential scan.
     *
     * Read from `pg_class.reltuples` rather than counted: the figure is
     * the planner's own, which is the figure that decided the plan being
     * judged. Counting would be more accurate and less relevant.
     *
     * @return array<string, int>
     */
    public function tableSizes(): array
    {
        if ($this->tableSizes !== null) {
            return $this->tableSizes;
        }

        /** @var array<int, object{relname: string, rows: float}> $rows */
        $rows = $this->db->select(<<<'SQL'
            SELECT c.relname, GREATEST(c.reltuples, 0) AS rows
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public' AND c.relkind = 'r'
            SQL);

        $sizes = [];

        foreach ($rows as $row) {
            $sizes[$row->relname] = (int) $row->rows;
        }

        return $this->tableSizes = $sizes;
    }

    /**
     * Only `SELECT` is explained.
     *
     * `EXPLAIN ANALYZE` executes what it is given, so explaining an
     * `INSERT` would write the row a second time. The surfaces here are
     * reads, but a surface that quietly recorded an interaction event
     * would otherwise double it, and a harness that corrupts the dataset
     * it is measuring is worse than no harness.
     */
    private function isReadable(string $sql): bool
    {
        return str_starts_with(strtolower(ltrim($sql)), 'select');
    }

    /**
     * @param  array<int, mixed>  $bindings
     * @return array{
     *     rows: int,
     *     planning_ms: float,
     *     execution_ms: float,
     *     shared_hit: int,
     *     shared_read: int,
     *     nodes: array<int, array{node: string, relation: string|null, index: string|null, actual_rows: int, estimated_rows: int, loops: int}>,
     * }|null
     */
    private function explain(string $sql, array $bindings): ?array
    {
        try {
            /** @var array<int, object> $rows */
            $rows = $this->db->select('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$sql, $bindings);
        } catch (Throwable) {
            // A statement PostgreSQL will not explain — a `SET`, or a
            // parameter type it cannot infer without the prepared
            // statement around it. Not a finding about the query.
            return null;
        }

        $first = $rows[0] ?? null;

        if ($first === null) {
            return null;
        }

        /** @var array<int, array<string, mixed>>|null $decoded */
        $decoded = json_decode((string) (((array) $first)['QUERY PLAN'] ?? ''), true);

        if (! is_array($decoded) || ! isset($decoded[0]['Plan']) || ! is_array($decoded[0]['Plan'])) {
            return null;
        }

        /** @var array<int, array{node: string, relation: string|null, index: string|null, actual_rows: int, estimated_rows: int, loops: int}> $nodes */
        $nodes = [];
        $buffers = ['hit' => 0, 'read' => 0];
        $this->walk($decoded[0]['Plan'], $nodes, $buffers);

        /** @var array{Plan: array<string, mixed>, 'Planning Time'?: float, 'Execution Time'?: float} $root */
        $root = $decoded[0];

        return [
            'rows' => (int) ($root['Plan']['Actual Rows'] ?? 0),
            'planning_ms' => round((float) ($root['Planning Time'] ?? 0), 3),
            'execution_ms' => round((float) ($root['Execution Time'] ?? 0), 3),
            'shared_hit' => $buffers['hit'],
            'shared_read' => $buffers['read'],
            'nodes' => $nodes,
        ];
    }

    /**
     * Flatten the plan tree into the handful of facts worth keeping.
     *
     * @param  array<string, mixed>  $plan
     * @param  array<int, array{node: string, relation: string|null, index: string|null, actual_rows: int, estimated_rows: int, loops: int}>  $nodes
     * @param  array{hit: int, read: int}  $buffers
     */
    private function walk(array $plan, array &$nodes, array &$buffers): void
    {
        $buffers['hit'] += (int) ($plan['Shared Hit Blocks'] ?? 0);
        $buffers['read'] += (int) ($plan['Shared Read Blocks'] ?? 0);

        $nodes[] = [
            'node' => (string) ($plan['Node Type'] ?? 'unknown'),
            'relation' => isset($plan['Relation Name']) ? (string) $plan['Relation Name'] : null,
            'index' => isset($plan['Index Name']) ? (string) $plan['Index Name'] : null,
            'actual_rows' => (int) ($plan['Actual Rows'] ?? 0),
            'estimated_rows' => (int) ($plan['Plan Rows'] ?? 0),
            'loops' => (int) ($plan['Actual Loops'] ?? 1),
        ];

        /** @var array<int, array<string, mixed>> $children */
        $children = is_array($plan['Plans'] ?? null) ? $plan['Plans'] : [];

        foreach ($children as $child) {
            $this->walk($child, $nodes, $buffers);
        }
    }
}
