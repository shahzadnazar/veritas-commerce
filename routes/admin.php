<?php

declare(strict_types=1);

use App\Modules\AdminPortal\Http\Controllers\AdminDashboardController;
use App\Modules\AdminPortal\Http\Controllers\AdminLoginController;
use Illuminate\Support\Facades\Route;

/*
 * The admin control centre.
 *
 * A separate guard and session from the customer surface, so a stolen
 * customer session can never be escalated toward admin. Each route also
 * declares the permission it needs, on top of its policy.
 */
Route::prefix('admin')->name('admin.')->group(function (): void {
    Route::get('login', [AdminLoginController::class, 'show'])->name('login');
    Route::post('login', [AdminLoginController::class, 'store'])->name('login.store');

    Route::middleware(['auth:admin', 'admin.can:dashboard.view'])->group(function (): void {
        Route::get('/', AdminDashboardController::class)->name('dashboard');
    });
});
