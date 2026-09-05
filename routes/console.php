<?php

declare(strict_types=1);

use App\Modules\Checkout\Jobs\ExpireCheckoutAttempts;
use App\Modules\Inventory\Jobs\ExpireReservations;
use App\Modules\Orders\Jobs\ExpireUnpaidOrders;
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

/*
 * And the rows behind those holds are closed too.
 *
 * Releasing an expired hold without closing what held it would leave an
 * order in pending_payment that nobody can fulfil, and an attempt in
 * Reserved whose idempotency key would replay into stock that is gone.
 * Both sweeps are idempotent and safe to interleave with the reservation
 * sweep in any order.
 */
Schedule::job(new ExpireUnpaidOrders)->everyMinute()->withoutOverlapping();
Schedule::job(new ExpireCheckoutAttempts)->everyMinute()->withoutOverlapping();

// The stored `reserved` column is only safe because something checks it.
/*
 * Seller earnings whose clearing period has elapsed become spendable, and
 * the orders they came from close.
 *
 * Hourly rather than daily: a seller whose money cleared at 09:00 should
 * not wait until the small hours to see it, and the pass is cheap because
 * it reads an indexed range of orders that are actually due. Overlapping
 * runs would be harmless — every write is a conditional UPDATE — but there
 * is no reason to have two.
 */
Schedule::command('earnings:clear')->hourly()->withoutOverlapping();

Schedule::command('inventory:reconcile')->dailyAt('03:15');

/*
 * Does the seller money add up?
 *
 * Reads only, and reports rather than repairs — a discrepancy in a
 * financial ledger is something a person has to look at, and a sweep that
 * quietly fixed it would destroy the evidence of what caused it. Daily,
 * just after the inventory reconciliation, so both land before anyone
 * looks at a dashboard.
 */
Schedule::command('finance:reconcile-sellers')->dailyAt('03:30');
