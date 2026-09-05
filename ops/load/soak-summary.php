<?php

declare(strict_types=1);

/**
 * Reads a soak the way a soak has to be read: in thirds.
 *
 * A sustained hold is not asking what the latency is — the ramp answered
 * that. It is asking whether the number the application gave in the first
 * minute is the number it still gives in the twelfth, and what grew while
 * it did. Averages over the whole run hide exactly that, so this splits
 * the resource samples into three and prints them side by side.
 *
 *   php ops/load/soak-summary.php [directory]
 */
$directory = $argv[1] ?? __DIR__.'/.run/soak';
$csv = $directory.'/resources.csv';

if (! is_file($csv)) {
    fwrite(STDERR, "No resource samples at {$csv}.\n");

    exit(1);
}

$rows = array_map('str_getcsv', file($csv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
$header = array_shift($rows);

if ($header === null || count($rows) < 6) {
    fwrite(STDERR, "Too few samples to say anything.\n");

    exit(1);
}

// The first two cover the sampler's own start and the ramp-in.
$rows = array_slice($rows, 2);
$third = (int) floor(count($rows) / 3);

$columns = ['probe_ms', 'cpu_idle_pct', 'mem_used_mb', 'pg_conns', 'pg_waiting', 'redis_mem_mb'];
$labels = ['probe ms', 'cpu busy %', 'memory MB', 'pg conns', 'lock waits', 'redis MB'];

/** @return array<string, float> */
$window = static function (array $slice) use ($header, $columns): array {
    $out = [];

    foreach ($columns as $name) {
        $index = array_search($name, $header, true);

        if ($index === false) {
            // A run recorded before this column existed.
            continue;
        }

        $values = array_map(static fn (array $row): float => (float) ($row[$index] ?? 0), $slice);
        $out[$name] = array_sum($values) / max(1, count($values));
    }

    if (array_key_exists('cpu_idle_pct', $out)) {
        $out['cpu_idle_pct'] = 100 - $out['cpu_idle_pct'];
    }

    return $out;
};

$windows = [
    'first third' => $window(array_slice($rows, 0, $third)),
    'middle' => $window(array_slice($rows, $third, $third)),
    'last third' => $window(array_slice($rows, 2 * $third)),
];

printf("%-12s %12s %12s %12s   %s\n", '', ...[...array_keys($windows), 'drift']);
echo str_repeat('-', 66)."\n";

foreach ($columns as $position => $name) {
    if (! array_key_exists($name, reset($windows))) {
        continue;
    }

    $values = array_map(static fn (array $w): float => $w[$name], $windows);
    $first = reset($values);
    $last = end($values);
    $drift = $first === 0.0 ? 0.0 : ($last - $first) / $first * 100;

    printf(
        "%-12s %12s %12s %12s   %+.0f%%\n",
        $labels[$position],
        number_format($values['first third'], 1),
        number_format($values['middle'], 1),
        number_format($values['last third'], 1),
        $drift,
    );
}

echo "\n";
echo "Latency that rises across the thirds is degradation; memory or\n";
echo "connections that rise without settling are the things to explain.\n";
