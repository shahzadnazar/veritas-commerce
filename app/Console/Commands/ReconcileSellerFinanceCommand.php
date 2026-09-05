<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Payouts\Queries\ReconcileSellerFinance as Reconcile;
use App\Modules\Payouts\Support\PayoutPolicy;
use Illuminate\Console\Command;

/**
 * `finance:reconcile-sellers` — does the money add up?
 *
 * Reports and exits non-zero on a discrepancy so CI and a scheduler can
 * both act on it. It repairs nothing, deliberately: §40 is explicit that a
 * reconciliation must not mutate records to make itself pass, because the
 * discrepancy is the only evidence of whatever caused it.
 */
final class ReconcileSellerFinanceCommand extends Command
{
    protected $signature = 'finance:reconcile-sellers {--currency=}';

    protected $description = 'Check that seller ledgers, payout reservations and settlements agree.';

    public function handle(Reconcile $reconcile): int
    {
        $currency = strtoupper((string) ($this->option('currency') ?: PayoutPolicy::currency()));

        $problems = $reconcile($currency);

        if ($problems === []) {
            $this->info("Seller finance reconciles in {$currency}.");

            return self::SUCCESS;
        }

        $this->error(count($problems)." discrepancies in {$currency}:");

        foreach ($problems as $problem) {
            $this->line("  {$problem['check']} — {$problem['subject']}: {$problem['detail']}");
        }

        $this->newLine();
        $this->warn('Nothing has been changed. These need a person.');

        return self::FAILURE;
    }
}
