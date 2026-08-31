<?php

declare(strict_types=1);

use App\Modules\Sellers\Console\PruneExpiredSellerInvitations;
use Illuminate\Support\Facades\Schedule;

/*
 * Scheduled work. Everything here is idempotent and safe to re-run.
 */

Schedule::command(PruneExpiredSellerInvitations::class)->hourly();
