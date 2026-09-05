<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

/*
 * Probes, outside every middleware group.
 *
 * Deliberately not in the `web` group. Sessions are stored in Redis in
 * production, so a health route inside that group would start a session
 * on every probe — which means liveness would depend on Redis, and a
 * Redis outage would restart every application container instead of
 * merely taking them out of the load balancer. The one thing liveness
 * must not do is depend on anything.
 *
 * No throttle either. These are polled continuously by design, and a
 * rate-limited health check is a health check that reports failure under
 * exactly the load it exists to survive.
 *
 * Public, because the payload is a status word and a coarse up/down per
 * dependency. Anything an operator would want beyond that — versions,
 * hostnames, queue depths — is diagnostic rather than operational and
 * belongs behind authentication.
 */
Route::get('health/live', [HealthController::class, 'live'])->name('health.live');
Route::get('health/ready', [HealthController::class, 'ready'])->name('health.ready');
