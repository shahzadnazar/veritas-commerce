<?php

declare(strict_types=1);

use App\Modules\Payments\Http\Controllers\ProviderWebhookController;
use Illuminate\Support\Facades\Route;

/*
 * Provider callbacks.
 *
 * Deliberately outside the `web` middleware group. A payment provider's
 * server has no session and no CSRF token, and §57 asks for the exclusion
 * to be exactly this route rather than a hole punched through the whole
 * payment area — so these are registered with no session middleware at all
 * instead of being added to a CSRF exception list that then quietly grows.
 *
 * What replaces CSRF is the signature: the endpoint verifies an HMAC over
 * the raw body against the configured secret before it does anything, and
 * an unsigned request is refused without being stored, queued or logged.
 *
 * The throttle is generous on purpose. A provider retrying a delivery it
 * has not had a 200 for is behaving correctly, and rate-limiting those
 * retries away would lose payment events (§58) — the abuse protection here
 * is the signature, not the counter.
 */
Route::post('webhooks/payments', ProviderWebhookController::class)
    ->middleware('throttle:600,1')
    ->name('webhooks.payments');
