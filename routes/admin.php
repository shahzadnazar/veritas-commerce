<?php

declare(strict_types=1);

use App\Modules\AdminPortal\Http\Controllers\AdminDashboardController;
use App\Modules\AdminPortal\Http\Controllers\AdminLoginController;
use App\Modules\AdminPortal\Http\Controllers\AdminTwoFactorController;
use Illuminate\Support\Facades\Route;

/*
 * The admin control centre.
 *
 * A separate guard and session from the customer surface, so a stolen
 * customer session can never be escalated toward admin. Beyond sign-in,
 * every route sits behind a confirmed second factor, and each declares the
 * permission it needs on top of its policy.
 */
Route::prefix('admin')->name('admin.')->group(function (): void {
    Route::get('login', [AdminLoginController::class, 'show'])->name('login');
    Route::post('login', [AdminLoginController::class, 'store'])->name('login.store');

    Route::middleware('auth:admin')->group(function (): void {
        Route::post('logout', [AdminLoginController::class, 'destroy'])->name('logout');

        // Reachable while enrolment is incomplete — this is the only area
        // that is, and RequireAdminTwoFactor sends everyone else here.
        Route::get('two-factor', [AdminTwoFactorController::class, 'setup'])->name('mfa.setup');
        Route::post('two-factor/start', [AdminTwoFactorController::class, 'start'])->name('mfa.start');
        Route::post('two-factor', [AdminTwoFactorController::class, 'store'])->name('mfa.store');
        Route::post('two-factor/recovery-codes', [AdminTwoFactorController::class, 'regenerate'])
            ->name('mfa.recovery');

        Route::middleware('admin.mfa')->group(function (): void {
            Route::get('/', AdminDashboardController::class)
                ->middleware('admin.can:dashboard.view')
                ->name('dashboard');
        });
    });
});
