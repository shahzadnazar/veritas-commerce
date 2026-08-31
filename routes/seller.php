<?php

declare(strict_types=1);

use App\Modules\Sellers\Http\Controllers\SellerDashboardController;
use Illuminate\Support\Facades\Route;

/*
 * The seller operating portal.
 *
 * Every route is behind an accepted membership, and the seller it scopes to
 * is derived from that membership rather than from anything in the request.
 */
Route::prefix('seller')
    ->middleware(['auth', 'seller'])
    ->name('seller.')
    ->group(function (): void {
        Route::get('/', SellerDashboardController::class)->name('dashboard');
    });
