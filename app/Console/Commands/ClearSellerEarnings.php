<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Orders\Actions\CompleteDeliveredSellerOrders;
use Illuminate\Console\Command;

/**
 * The scheduled pass that makes cleared earnings spendable.
 *
 * Safe to run by hand, safe to run twice, safe to run while another copy
 * is running: it writes no money, only availability, and every write is a
 * conditional UPDATE that the second caller loses.
 */
final class ClearSellerEarnings extends Command
{
    protected $signature = 'earnings:clear
        {--limit=500 : Maximum seller orders to settle in one pass}';

    protected $description = 'Release delivered seller earnings whose clearing period has elapsed';

    public function handle(CompleteDeliveredSellerOrders $settle): int
    {
        ['released' => $released, 'completed' => $completed] = $settle(max(1, (int) $this->option('limit')));

        $this->line("released={$released} completed={$completed}");

        return self::SUCCESS;
    }
}
