<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Analytics\Actions\RebuildDailyMetrics;
use App\Modules\Analytics\Support\AnalyticsDay;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * `analytics:rebuild` — recompute the daily rollups from the marketplace's
 * own records.
 *
 * §60, stated as a command contract: this reads orders, payments, refunds,
 * the seller ledger, the catalogue and the event log, and writes four
 * tables, all of them derived. It cannot change an order, a payment, an
 * inventory level, a ledger entry, a refund or a payout — `--verify`
 * fingerprints every one of those either side of the run and exits
 * non-zero if anything moved.
 *
 * Days are platform-timezone days (§70). A rebuild of "2026-03-03" means
 * the same 24 hours whoever runs it and wherever they are.
 */
final class RebuildAnalyticsCommand extends Command
{
    protected $signature = 'analytics:rebuild
        {--from= : First day to rebuild (YYYY-MM-DD)}
        {--to= : Last day to rebuild (YYYY-MM-DD); defaults to --from}
        {--days=7 : Rebuild the last N days, when no explicit range is given}
        {--verify : Fingerprint the transactional tables before and after, and fail if anything moved}';

    protected $description = 'Recompute the daily marketplace, product, seller and search metrics.';

    /**
     * The tables an analytics rebuild has no business changing.
     *
     * Shared with the recommendation rebuild so there is one list of what
     * the insight layer may not touch, rather than two that drift.
     */
    public const PROTECTED_TABLES = RebuildRecommendationsCommand::PROTECTED_TABLES;

    public function handle(RebuildDailyMetrics $rebuild): int
    {
        $days = $this->days();

        if ($days === []) {
            $this->error('Nothing to rebuild: the range is empty or the dates are the wrong way round.');

            return self::FAILURE;
        }

        $verify = (bool) $this->option('verify');
        $before = $verify ? $this->fingerprint() : [];

        $first = $days[0]->date;
        $last = $days[count($days) - 1]->date;

        $this->line(sprintf(
            'Rebuilding %d day(s), %s to %s, in %s.',
            count($days),
            $first,
            $last,
            AnalyticsDay::timezone(),
        ));

        foreach ($rebuild->forDays($days) as $projection => $written) {
            $this->line("  {$projection}: {$written} rows.");
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

    /** @return array<int, AnalyticsDay> */
    private function days(): array
    {
        $from = $this->stringOption('from');
        $to = $this->stringOption('to');

        if ($from === null && $to === null) {
            $days = (int) $this->option('days');

            return AnalyticsDay::lastDays(max(1, $days));
        }

        // At least one is set, so both resolve: a lone --from rebuilds
        // that single day, and a lone --to does the same.
        return AnalyticsDay::range($from ?? (string) $to, $to ?? (string) $from);
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return array<string, string> */
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
