<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Diagnostics\DestructiveDatabaseGuard;
use App\Support\Performance\PerformanceDataset;
use App\Support\Performance\PerformanceScale;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use SimpleXMLElement;
use Throwable;

/**
 * Fill a database with a launch-sized marketplace.
 *
 * This command empties its target and rebuilds it, which puts it in the
 * same category as `migrate:fresh` — so it carries the same protections,
 * plus one more that is specific to what it does.
 *
 *   1. It refuses in a production-looking environment.
 *   2. It refuses a database named in `VERITAS_PROTECTED_DATABASES`.
 *   3. It refuses the database PHPUnit is configured to use, read out of
 *      `phpunit.xml` rather than assumed. Seeding a quarter of a million
 *      interaction events into the test database would not corrupt
 *      anything — `RefreshDatabase` would wipe it on the next run — but
 *      it would make the suite crawl and the cause would be invisible.
 *   4. It names the database out loud and waits, unless `--force` says
 *      an operator has already decided.
 *
 * Nothing it writes can be mistaken for real data: every `public_id`
 * carries the `0PERF` marker, and `veritas:production-check` fails if a
 * single marked row is present.
 */
final class SeedPerformanceDataset extends Command
{
    protected $signature = 'veritas:seed-performance
        {--scale=phase1 : Dataset size — phase1 or small}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Rebuild a non-production database at launch scale, for query-plan work';

    public function handle(): int
    {
        try {
            $scale = PerformanceScale::named((string) $this->option('scale'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $guard = DestructiveDatabaseGuard::forCurrentRequest();
        $database = $guard->targetDatabase();

        if ($refusal = $this->refusalReason($guard, $database)) {
            $this->error($refusal);

            return self::FAILURE;
        }

        $this->warn($guard->announcement());
        $this->warn('Every table except `migrations` will be emptied and rebuilt.');

        if (! $this->option('force') && ! $this->confirm("Rebuild \"{$database}\" at {$scale->name} scale?", false)) {
            $this->line('Nothing was changed.');

            return self::FAILURE;
        }

        $this->newLine();

        foreach ($scale->summary() as $label => $count) {
            $this->line(sprintf('  %-12s %s', $label, number_format($count)));
        }

        $this->newLine();

        $startedAt = microtime(true);

        try {
            $counts = (new PerformanceDataset(DB::connection()))->build(
                $scale,
                function (string $message): void {
                    $this->line('  '.$message.' ...');
                },
            );
        } catch (Throwable $e) {
            $this->newLine();
            $this->error('The build failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->table(
            ['Table', 'Rows'],
            array_map(
                static fn (string $table, int $rows): array => [$table, number_format($rows)],
                array_keys($counts),
                array_values($counts),
            ),
        );

        $this->info(sprintf(
            'Built %s rows in %s across %d statements. Target: %s.',
            number_format(array_sum($counts)),
            $this->elapsed($startedAt),
            count($counts),
            $database,
        ));

        return self::SUCCESS;
    }

    /**
     * Why this must not run here.
     *
     * Ordered from the most serious refusal down, so the message an
     * operator sees names the worst thing about what they just typed
     * rather than the first thing checked.
     */
    private function refusalReason(DestructiveDatabaseGuard $guard, string $database): ?string
    {
        if ($guard->isProductionEnvironment()) {
            return sprintf(
                'APP_ENV is "%s". This command writes fictional sellers, orders and money; it does not run here.',
                (string) config('app.env'),
            );
        }

        if ($guard->isProtected($database)) {
            return sprintf('Database "%s" is listed in VERITAS_PROTECTED_DATABASES. Refusing.', $database);
        }

        if ($database !== '' && $database === $this->phpunitDatabase()) {
            return sprintf(
                'Database "%s" is the one phpunit.xml uses. Seeding it would make the suite unusably slow. '
                .'Point DB_DATABASE at a scratch database instead.',
                $database,
            );
        }

        if ($database === '') {
            return 'No target database is configured. Refusing to guess.';
        }

        return null;
    }

    /**
     * The database the test suite runs against, read from phpunit.xml.
     *
     * Read rather than assumed, because the trap this closes is the same
     * one the destructive-database guard exists for: `veritas_test` is a
     * convention, not a fact, and a repository that renamed it would
     * silently lose the protection.
     */
    private function phpunitDatabase(): ?string
    {
        $path = base_path('phpunit.xml');

        if (! is_file($path)) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_file($path);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            return null;
        }

        /** @var array<int, SimpleXMLElement> $matches */
        $matches = $xml->xpath('//php/env[@name="DB_DATABASE"]') ?: [];

        foreach ($matches as $node) {
            $value = (string) ($node['value'] ?? '');

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function elapsed(float $startedAt): string
    {
        $seconds = microtime(true) - $startedAt;

        return $seconds < 90
            ? sprintf('%.1fs', $seconds)
            : sprintf('%dm %ds', (int) ($seconds / 60), (int) $seconds % 60);
    }
}
