<?php

declare(strict_types=1);

use App\Modules\AdminPortal\Http\Controllers\AdminAnalyticsController;
use App\Modules\AdminPortal\Http\Controllers\AdminDashboardController;
use App\Modules\AdminPortal\Http\Controllers\AdminFinanceController;
use App\Modules\AdminPortal\Http\Controllers\AdminFulfilmentController;
use App\Modules\AdminPortal\Http\Controllers\AdminLoginController;
use App\Modules\AdminPortal\Http\Controllers\AdminOrderController;
use App\Modules\AdminPortal\Http\Controllers\AdminPaymentController;
use App\Modules\AdminPortal\Http\Controllers\AdminPayoutController;
use App\Modules\AdminPortal\Http\Controllers\AdminReviewController;
use App\Modules\AdminPortal\Http\Controllers\AdminStaffController;
use App\Modules\AdminPortal\Http\Controllers\AdminTwoFactorController;
use App\Modules\AdminPortal\Http\Controllers\ApplicationDocumentController;
use App\Modules\AdminPortal\Http\Controllers\CatalogueProductController;
use App\Modules\AdminPortal\Http\Controllers\CatalogueTaxonomyController;
use App\Modules\AdminPortal\Http\Controllers\InventoryOversightController;
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

            /*
             * Catalogue moderation. Each route names the permission it
             * needs; the controllers check the same one again, so neither
             * alone is the only thing between a role and a decision that
             * changes what the marketplace sells.
             */
            Route::prefix('catalogue')->name('catalogue.')->group(function (): void {
                Route::get('products', [CatalogueProductController::class, 'index'])
                    ->middleware('admin.can:catalog.view')->name('products.index');
                Route::get('products/{product}', [CatalogueProductController::class, 'show'])
                    ->middleware('admin.can:catalog.view')->name('products.show');
                // The authorised half of canonical ownership: a seller
                // cannot change a product other sellers list against, so
                // the catalogue team can.
                Route::patch('products/{product}', [CatalogueProductController::class, 'update'])
                    ->middleware('admin.can:catalog.product.review')->name('products.update');
                Route::post('products/{product}/approve', [CatalogueProductController::class, 'approve'])
                    ->middleware('admin.can:catalog.product.approve')->name('products.approve');
                Route::post('products/{product}/reject', [CatalogueProductController::class, 'reject'])
                    ->middleware('admin.can:catalog.product.reject')->name('products.reject');
                Route::post('products/{product}/request-changes', [CatalogueProductController::class, 'requestChanges'])
                    ->middleware('admin.can:catalog.product.review')->name('products.request-changes');
                Route::post('products/{product}/suspend', [CatalogueProductController::class, 'suspend'])
                    ->middleware('admin.can:catalog.product.suspend')->name('products.suspend');

                // Search health: what customers look for, and what they
                // look for and do not find.
                Route::get('search-health', [CatalogueProductController::class, 'searchHealth'])
                    ->middleware('admin.can:catalog.view')->name('search-health');

                // The taxonomy every seller lists against.
                Route::get('taxonomy', [CatalogueTaxonomyController::class, 'index'])
                    ->middleware('admin.can:catalog.view')->name('taxonomy');
                Route::post('categories', [CatalogueTaxonomyController::class, 'storeCategory'])
                    ->middleware('admin.can:catalog.category.manage')->name('categories.store');
                Route::patch('categories/{category}', [CatalogueTaxonomyController::class, 'updateCategory'])
                    ->middleware('admin.can:catalog.category.manage')->name('categories.update');
                Route::post('categories/{category}/attributes', [CatalogueTaxonomyController::class, 'attachAttribute'])
                    ->middleware('admin.can:catalog.category.manage')->name('categories.attributes');
                Route::post('attributes', [CatalogueTaxonomyController::class, 'storeAttribute'])
                    ->middleware('admin.can:catalog.attribute.manage')->name('attributes.store');
                Route::post('brands/{brand}/approve', [CatalogueTaxonomyController::class, 'approveBrand'])
                    ->middleware('admin.can:catalog.brand.manage')->name('brands.approve');
            });

            /*
             * Stock oversight. Reading a seller's count answers "why can
             * nobody buy this"; changing it is the platform reaching into
             * their business, so it is a separate permission and always
             * carries a written reason.
             */
            /*
             * Orders. `orders.view` opens the screen; the finance columns
             * on it need `orders.view_sensitive` as well, which the
             * controller checks — support can answer a delivery question
             * without seeing what the platform took from each seller.
             */
            Route::prefix('orders')->name('orders.')->group(function (): void {
                Route::get('/', [AdminOrderController::class, 'index'])
                    ->middleware('admin.can:orders.view')->name('index');
                Route::get('{reference}', [AdminOrderController::class, 'show'])
                    ->middleware('admin.can:orders.view')->name('show');
            });

            /*
             * Payments. `payments.view` opens the screens; issuing a
             * refund is its own permission because it is the one action in
             * the application that takes money back out of the platform's
             * account, and the raw provider events sit behind a third —
             * "did this order pay" and "what exactly did Stripe send at
             * 03:14" are different questions with different audiences.
             */
            Route::prefix('payments')->name('payments.')->group(function (): void {
                Route::get('/', [AdminPaymentController::class, 'index'])
                    ->middleware('admin.can:payments.view')->name('index');
                Route::get('{reference}', [AdminPaymentController::class, 'show'])
                    ->middleware('admin.can:payments.view')->name('show');
                Route::post('{reference}/refunds', [AdminPaymentController::class, 'refund'])
                    ->middleware(['admin.can:orders.refund', 'throttle:30,1'])->name('refund');
            });

            /*
             * Fulfilment across every seller.
             *
             * Reading it is support's job; correcting a customer's
             * tracking and deciding that a parcel arrived are the
             * platform contradicting a seller's own record of their own
             * shipment, so each takes its own permission and a written
             * reason. There is deliberately no "set status" route.
             */
            Route::prefix('fulfilment')->name('fulfilment.')->group(function (): void {
                Route::get('/', [AdminFulfilmentController::class, 'index'])
                    ->middleware('admin.can:fulfilment.view')->name('index');
                Route::get('{reference}', [AdminFulfilmentController::class, 'show'])
                    ->middleware('admin.can:fulfilment.view')->name('show');
                Route::post('{reference}/shipments/{shipment}/deliver', [AdminFulfilmentController::class, 'deliver'])
                    ->middleware(['admin.can:fulfilment.override', 'throttle:60,1'])->name('deliver');
                Route::post('{reference}/shipments/{shipment}/tracking', [AdminFulfilmentController::class, 'correctTracking'])
                    ->middleware(['admin.can:fulfilment.tracking.correct', 'throttle:60,1'])->name('tracking');
            });

            /*
             * Money.
             *
             * Five permissions across seven routes, because reading the
             * queue, picking a request up, authorising it, refusing it and
             * recording that money left are five different acts of trust.
             * Only the last writes to a seller's ledger, and only finance
             * holds it.
             */
            Route::prefix('payouts')->name('payouts.')->group(function (): void {
                Route::get('/', [AdminPayoutController::class, 'index'])
                    ->middleware('admin.can:payouts.view')->name('index');
                Route::get('{reference}', [AdminPayoutController::class, 'show'])
                    ->middleware('admin.can:payouts.view')->name('show');
                Route::post('{reference}/review', [AdminPayoutController::class, 'review'])
                    ->middleware(['admin.can:payouts.review', 'throttle:60,1'])->name('review');
                Route::post('{reference}/approve', [AdminPayoutController::class, 'approve'])
                    ->middleware(['admin.can:payouts.approve', 'throttle:60,1'])->name('approve');
                Route::post('{reference}/reject', [AdminPayoutController::class, 'reject'])
                    ->middleware(['admin.can:payouts.reject', 'throttle:60,1'])->name('reject');
                Route::post('{reference}/settlement', [AdminPayoutController::class, 'startSettlement'])
                    ->middleware(['admin.can:payouts.settle', 'throttle:60,1'])->name('settlement');
                Route::post('{reference}/settle', [AdminPayoutController::class, 'settle'])
                    ->middleware(['admin.can:payouts.settle', 'throttle:60,1'])->name('settle');
                Route::post('{reference}/fail', [AdminPayoutController::class, 'fail'])
                    ->middleware(['admin.can:payouts.settle', 'throttle:60,1'])->name('fail');
                Route::post('{reference}/cancel', [AdminPayoutController::class, 'cancel'])
                    ->middleware(['admin.can:payouts.reject', 'throttle:60,1'])->name('cancel');
            });

            /*
             * Review moderation. Reading the queue and changing a review
             * are different permissions, because hiding one moves a
             * product's public rating.
             */
            Route::prefix('reviews')->name('reviews.')->group(function (): void {
                Route::get('/', [AdminReviewController::class, 'index'])
                    ->middleware('admin.can:reviews.view')->name('index');
                Route::post('{review}/hide', [AdminReviewController::class, 'hide'])
                    ->middleware(['admin.can:reviews.moderate', 'throttle:60,1'])->name('hide');
                Route::post('{review}/reject', [AdminReviewController::class, 'reject'])
                    ->middleware(['admin.can:reviews.moderate', 'throttle:60,1'])->name('reject');
                Route::post('{review}/restore', [AdminReviewController::class, 'restore'])
                    ->middleware(['admin.can:reviews.moderate', 'throttle:60,1'])->name('restore');
            });

            /*
             * Marketplace analytics. One route, GET only: §2 says nothing
             * behind this permission may change anything, and the surface
             * says so rather than relying on the controller to.
             */
            Route::prefix('analytics')->name('analytics.')->group(function (): void {
                Route::get('/', [AdminAnalyticsController::class, 'index'])
                    ->middleware('admin.can:analytics.view')->name('index');
            });

            Route::prefix('finance')->name('finance.')->group(function (): void {
                Route::get('/', [AdminFinanceController::class, 'index'])
                    ->middleware('admin.can:earnings.view')->name('index');
            });

            Route::prefix('inventory')->name('inventory.')->group(function (): void {
                Route::get('/', [InventoryOversightController::class, 'index'])
                    ->middleware('admin.can:inventory.view')->name('index');
                Route::get('{offer}', [InventoryOversightController::class, 'show'])
                    ->middleware('admin.can:inventory.view')->name('show');
                Route::post('{offer}/adjust', [InventoryOversightController::class, 'adjust'])
                    ->middleware('admin.can:inventory.adjust')->name('adjust');
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

                // One seller's whole financial picture, and the
                // exceptional correction. §72 and §64.
                Route::get('{seller}/finance', [AdminPayoutController::class, 'sellerFinance'])
                    ->middleware('admin.can:earnings.view')->name('finance');
                Route::post('{seller}/finance/adjust', [AdminPayoutController::class, 'adjust'])
                    ->middleware(['admin.can:finance.adjust', 'throttle:20,1'])->name('finance.adjust');
            });
        });
    });
});
