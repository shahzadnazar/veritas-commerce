<?php

declare(strict_types=1);

/**
 * Turns a ramp's per-level output into the table the M9 report carries.
 *
 * Reads the k6 JSON and the resource CSV for each level and prints one
 * row per level: throughput, the latency percentiles, the failure rate,
 * and what the machine was doing while it happened. The resource columns
 * are the point — a p95 that doubled while the CPU still had headroom is
 * a different finding from one that doubled with the cores saturated.
 *
 *   php ops/load/summarise.php [directory]
 */
$directory = $argv[1] ?? __DIR__.'/.run/ramp';

$levels = glob($directory.'/level-*.json') ?: [];

usort($levels, static fn (string $a, string $b): int => vus($a) <=> vus($b));

function vus(string $path): int
{
    preg_match('/level-(\d+)\.json$/', $path, $matches);

    return (int) ($matches[1] ?? 0);
}

/** @return array<string, float> */
function resources(string $path): array
{
    if (! is_file($path)) {
        return [];
    }

    $rows = array_filter(array_map('str_getcsv', file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)));
    $header = array_shift($rows);

    if ($header === null || $rows === []) {
        return [];
    }

    // The first two samples cover the sampler's own startup and the
    // level's ramp-in; they describe neither idle nor load.
    $rows = array_slice($rows, 2);
    $columns = [];

    foreach ($rows as $row) {
        foreach ($header as $index => $name) {
            $columns[$name][] = (float) ($row[$index] ?? 0);
        }
    }

    if ($columns === []) {
        return [];
    }

    return [
        'cpu_busy' => 100 - (array_sum($columns['cpu_idle_pct']) / count($columns['cpu_idle_pct'])),
        'load1' => max($columns['load1']),
        'pg_conns' => max($columns['pg_conns']),
        'pg_active' => max($columns['pg_active']),
        'pg_waiting' => max($columns['pg_waiting']),
        'longest_query' => max($columns['pg_longest_s']),
        'redis_mb' => max($columns['redis_mem_mb']),
        'mem_mb' => max($columns['mem_used_mb']),
    ];
}

$rows = [[
    'VUs', 'req/s', 'n', 'p50', 'p95', 'p99', 'max', 'fail%',
    'cpu%', 'load', 'pg', 'act', 'lock', 'redisMB',
]];

foreach ($levels as $path) {
    /** @var array{metrics: array<string, array{values: array<string, float>}>} $data */
    $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    $latency = $data['metrics']['mix_duration']['values'];
    $requests = $data['metrics']['http_reqs']['values'];
    $failed = $data['metrics']['mix_failed']['values'];
    $machine = resources(dirname($path).'/resources-'.vus($path).'.csv');

    $rows[] = [
        (string) vus($path),
        number_format($requests['rate'], 1),
        (string) (int) $latency['count'],
        number_format($latency['med'], 0),
        number_format($latency['p(95)'], 0),
        number_format($latency['p(99)'], 0),
        number_format($latency['max'], 0),
        number_format($failed['rate'] * 100, 2),
        $machine === [] ? '-' : number_format($machine['cpu_busy'], 0),
        $machine === [] ? '-' : number_format($machine['load1'], 1),
        $machine === [] ? '-' : (string) (int) $machine['pg_conns'],
        $machine === [] ? '-' : (string) (int) $machine['pg_active'],
        $machine === [] ? '-' : (string) (int) $machine['pg_waiting'],
        $machine === [] ? '-' : number_format($machine['redis_mb'], 1),
    ];
}

if (count($rows) === 1) {
    fwrite(STDERR, "No levels found in {$directory}.\n");

    exit(1);
}

$widths = [];

foreach ($rows as $row) {
    foreach ($row as $column => $cell) {
        $widths[$column] = max($widths[$column] ?? 0, strlen($cell));
    }
}

foreach ($rows as $index => $row) {
    $line = [];

    foreach ($row as $column => $cell) {
        $line[] = str_pad($cell, $widths[$column], ' ', $column === 0 ? STR_PAD_LEFT : STR_PAD_LEFT);
    }

    echo implode('  ', $line)."\n";

    if ($index === 0) {
        echo str_repeat('-', array_sum($widths) + 2 * count($widths) - 2)."\n";
    }
}
