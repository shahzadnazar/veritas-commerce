<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Reviews\Queries\ReconcileRatings;
use Illuminate\Console\Command;

/**
 * `reviews:reconcile-ratings` — do the ratings match the reviews?
 *
 * Reports by default and exits non-zero on a discrepancy, so CI and a
 * scheduler can both act on it. `--repair` recomputes the rows that were
 * wrong, which is safe here in a way it would not be for the ledger: a
 * rating summary is derived, and its only correct value is the one
 * recomputation produces.
 */
final class ReconcileRatingsCommand extends Command
{
    protected $signature = 'reviews:reconcile-ratings {--repair : Recompute the summaries that disagree}';

    protected $description = 'Check product rating summaries against the reviews they are derived from.';

    public function handle(ReconcileRatings $reconcile): int
    {
        $repair = (bool) $this->option('repair');

        $problems = $reconcile($repair);

        if ($problems === []) {
            $this->info('Every product rating summary matches its reviews.');

            return self::SUCCESS;
        }

        $this->error(count($problems).' rating summaries disagree with their reviews:');

        foreach ($problems as $problem) {
            $this->line("  product #{$problem['product_id']} — {$problem['check']}: {$problem['detail']}");
        }

        $this->newLine();

        if ($repair) {
            $this->info('Repaired. Rerun to confirm.');

            return self::SUCCESS;
        }

        $this->warn('Nothing has been changed. Rerun with --repair to recompute them.');

        return self::FAILURE;
    }
}
