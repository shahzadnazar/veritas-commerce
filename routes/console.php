<?php

declare(strict_types=1);

use App\Modules\Inventory\Jobs\ExpireReservations;
use App\Modules\Sellers\Console\PruneExpiredSellerInvitations;
use Illuminate\Support\Facades\Schedule;

/*
 * Scheduled work. Everything here is idempotent and safe to re-run.
 */

Schedule::command(PruneExpiredSellerInvitations::class)->hourly();

/*
 * Abandoned checkouts give their stock back.
 *
 * Every minute, because the cost of waiting is a sellable unit that nobody
 * can buy. Queued rather than run inline so a slow sweep cannot overlap
 * itself into the next tick, and `withoutOverlapping` for the same reason
 * at the scheduler level.
 */
Schedule::job(new ExpireReservations)->everyMinute()->withoutOverlapping();

// The stored `reserved` column is only safe because something checks it.
Schedule::command('inventory:reconcile')->dailyAt('03:15');
