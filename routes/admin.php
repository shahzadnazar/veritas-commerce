<?php

declare(strict_types=1);

use App\Modules\AdminPortal\Http\Controllers\AdminDashboardController;
use App\Modules\AdminPortal\Http\Controllers\AdminLoginController;
use App\Modules\AdminPortal\Http\Controllers\AdminStaffController;
use App\Modules\AdminPortal\Http\Controllers\AdminTwoFactorController;
use App\Modules\AdminPortal\Http\Controllers\ApplicationDocumentController;
use App\Modules\AdminPortal\Http\Controllers\SellerAccountController;
use App\Modules\AdminPortal\Http\Controllers\SellerApplicationController;
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

            // Application review. Each route declares the permission it
            // needs; the controller re-checks it, so neither alone is the
            // only thing standing between a role and an action.
            Route::prefix('applications')->name('applications.')->group(function (): void {
                Route::get('/', [SellerApplicationController::class, 'index'])
                    ->middleware('admin.can:seller.application.view')->name('index');
                Route::get('{application}', [SellerApplicationController::class, 'show'])
                    ->middleware('admin.can:seller.application.view')->name('show');
                Route::post('{application}/review', [SellerApplicationController::class, 'beginReview'])
                    ->middleware('admin.can:seller.application.review')->name('review');
                Route::post('{application}/approve', [SellerApplicationController::class, 'approve'])
                    ->middleware('admin.can:seller.approve')->name('approve');
                Route::post('{application}/reject', [SellerApplicationController::class, 'reject'])
                    ->middleware('admin.can:seller.reject')->name('reject');
                Route::post('{application}/request-changes', [SellerApplicationController::class, 'requestChanges'])
                    ->middleware('admin.can:seller.application.review')->name('request-changes');

                // Paperwork carries the same class of information as a tax
                // ID, so it needs the same permission.
                Route::get('documents/{document}', [ApplicationDocumentController::class, 'show'])
                    ->middleware('admin.can:seller.view_sensitive')->name('documents.show');
            });

            // Staff accounts. Only the permission that lets someone
            // reset another person's second factor opens this at all.
            Route::prefix('staff')->name('staff.')->group(function (): void {
                Route::get('/', [AdminStaffController::class, 'index'])
                    ->middleware('admin.can:staff.reset_mfa')->name('index');
                Route::post('{admin}/reset-two-factor', [AdminStaffController::class, 'resetTwoFactor'])
                    ->middleware('admin.can:staff.reset_mfa')->name('reset-mfa');
            });

            // Governance of sellers already trading, which is a different
            // decision from deciding an application.
            Route::prefix('sellers')->name('sellers.')->group(function (): void {
                Route::get('/', [SellerAccountController::class, 'index'])
                    ->middleware('admin.can:seller.application.view')->name('index');
                Route::post('{seller}/suspend', [SellerAccountController::class, 'suspend'])
                    ->middleware('admin.can:seller.suspend')->name('suspend');
                Route::post('{seller}/reactivate', [SellerAccountController::class, 'reactivate'])
                    ->middleware('admin.can:seller.reactivate')->name('reactivate');
            });
        });
    });
});
