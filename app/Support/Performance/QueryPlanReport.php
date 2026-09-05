<?php

declare(strict_types=1);

namespace App\Support\Performance;

/**
 * What one read surface cost, and how PostgreSQL answered it.
 *
 * Deliberately not the plan text. A captured `EXPLAIN` output is a
 * brittle thing to keep — it moves with the PostgreSQL version, the
 * statistics, the row estimates and even the width of the terminal — so
 * what is kept here is the part that carries meaning across all of that:
 * which relations were touched, by what access method, how many rows
 * came back against how many were predicted, and how many pages were
 * read. Those are comparable between runs. "Cost=1234.56..7890.12" is
 * not.
 */
final readonly class QueryPlanReport
{
    /**
     * @param  array<int, array{
     *     sql: string,
     *     ms: float,
     *     rows: int,
     *     planning_ms: float,
     *     execution_ms: float,
     *     shared_hit: int,
     *     shared_read: int,
     *     nodes: array<int, array{node: string, relation: string|null, index: string|null, actual_rows: int, estimated_rows: int, loops: int}>,
     * }>  $statements
     */
    public function __construct(
        public string $group,
        public string $name,
        public int $queryCount,
        public float $totalMs,
        public array $statements,
    ) {}

    /** The single slowest statement, which is the one worth arguing about. */
    /** @return array<string, mixed>|null */
    public function slowest(): ?array
    {
        if ($this->statements === []) {
            return null;
        }

        $sorted = $this->statements;
        usort($sorted, static fn (array $a, array $b): int => $b['ms'] <=> $a['ms']);

        return $sorted[0];
    }

    /**
     * Sequential scans over relations big enough for it to matter.
     *
     * Not every sequential scan is a defect — reading a two-hundred-row
     * category table end to end is cheaper than descending an index, and
     * PostgreSQL is right to do it. What deserves attention is a
     * sequential scan over a table with tens of thousands of rows,
     * especially one repeated per outer row.
     *
     * @param  array<string, int>  $tableSizes
     * @return array<int, array{relation: string, rows: int, loops: int, table_rows: int}>
     */
    public function significantSequentialScans(array $tableSizes, int $threshold): array
    {
        $found = [];

        foreach ($this->statements as $statement) {
            foreach ($statement['nodes'] as $node) {
                $relation = $node['relation'];

                if ($node['node'] !== 'Seq Scan' || $relation === null) {
                    continue;
                }

                $size = $tableSizes[$relation] ?? 0;

                if ($size < $threshold) {
                    continue;
                }

                $found[] = [
                    'relation' => $relation,
                    'rows' => $node['actual_rows'],
                    'loops' => $node['loops'],
                    'table_rows' => $size,
                ];
            }
        }

        return $found;
    }

    /**
     * Node types where a row estimate means something.
     *
     * Every other node in a plan reports numbers that look like
     * misestimates and are not. A `Sort` under a `Limit` is estimated on
     * its input and measured on its output, so a top-N heapsort that
     * discarded a thousand rows reads as a 40x error. `BitmapAnd` and
     * `BitmapOr` do not report rows at all, so they read as infinite
     * ones. Including them buried the two real findings under thirty
     * artefacts.
     */
    private const ESTIMATED_NODES = [
        'Seq Scan', 'Index Scan', 'Index Only Scan', 'Bitmap Heap Scan',
        'Nested Loop', 'Hash Join', 'Merge Join',
    ];

    /**
     * Scan and join nodes that returned far more rows than predicted.
     *
     * Underestimates only, and deliberately. An overestimate usually
     * means a `LIMIT` stopped a scan early, which is the plan working;
     * an underestimate is what makes the planner choose a nested loop
     * for three rows and then execute it thirty thousand times. That is
     * the shape of nearly every query that was fast in staging and timed
     * out in production, so it is worth reporting even where the surface
     * is currently quick.
     *
     * @return array<int, array{node: string, relation: string|null, estimated: int, actual: int}>
     */
    public function misestimates(int $factor = 10, int $floor = 1_000): array
    {
        $found = [];

        foreach ($this->statements as $statement) {
            foreach ($statement['nodes'] as $node) {
                if (! in_array($node['node'], self::ESTIMATED_NODES, true)) {
                    continue;
                }

                $loops = max(1, $node['loops']);
                $actual = $node['actual_rows'] * $loops;
                $estimated = max(1, $node['estimated_rows'] * $loops);

                if ($actual < $floor || $actual < $estimated * $factor) {
                    continue;
                }

                $found[] = [
                    'node' => $node['node'],
                    'relation' => $node['relation'],
                    'estimated' => $estimated,
                    'actual' => $actual,
                ];
            }
        }

        return $found;
    }
}
