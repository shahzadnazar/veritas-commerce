<?php

declare(strict_types=1);

use App\Modules\Analytics\Http\Controllers\SellerAnalyticsController;
use App\Modules\Catalog\Http\Controllers\SellerCatalogueController;
use App\Modules\Inventory\Http\Controllers\SellerInventoryController;
use App\Modules\Offers\Http\Controllers\SellerOfferController;
use App\Modules\Orders\Http\Controllers\SellerFulfilmentController;
use App\Modules\Orders\Http\Controllers\SellerOrderController;
use App\Modules\Payouts\Http\Controllers\SellerFinanceController;
use App\Modules\Sellers\Http\Controllers\SellerApplicationController;
use App\Modules\Sellers\Http\Controllers\SellerDashboardController;
use App\Modules\Sellers\Http\Controllers\SellerDocumentController;
use App\Modules\Sellers\Http\Controllers\SellerInvitationController;
use App\Modules\Sellers\Http\Controllers\SellerStoreController;
use App\Modules\Sellers\Http\Controllers\SellerTeamController;
use Illuminate\Support\Facades\Route;

/*
 * The seller operating portal.
 *
 * Applying is open to any signed-in customer; everything else requires an
 * accepted membership, and the seller it scopes to is derived from that
 * membership rather than from anything in the request.
 */
Route::prefix('seller')->name('seller.')->middleware('auth')->group(function (): void {
    // Applying, and accepting an invitation, both happen before the actor
    // is a member of anything — so they sit outside the membership gate.
    Route::get('apply', [SellerApplicationController::class, 'show'])->name('apply');
    Route::post('apply', [SellerApplicationController::class, 'store'])->name('apply.store');

    // Verification paperwork belongs to the application, which belongs to
    // the signed-in applicant — so these sit with `apply`, outside the
    // membership gate, and resolve nothing from the request but the id of
    // a document the applicant already owns.
    Route::post('apply/documents', [SellerDocumentController::class, 'store'])->name('apply.documents.store');
    Route::get('apply/documents/{document}', [SellerDocumentController::class, 'show'])->name('apply.documents.show');
    Route::delete('apply/documents/{document}', [SellerDocumentController::class, 'destroy'])->name('apply.documents.destroy');

    Route::get('invitations/{invitation}', [SellerInvitationController::class, 'show'])->name('invitations.show');
    Route::post('invitations/{invitation}', [SellerInvitationController::class, 'accept'])->name('invitations.accept');

    Route::middleware('seller')->group(function (): void {
        Route::get('/', SellerDashboardController::class)->name('dashboard');

        Route::get('store', [SellerStoreController::class, 'edit'])
            ->middleware('seller.can:store.manage')->name('store');
        Route::post('store', [SellerStoreController::class, 'update'])
            ->middleware('seller.can:store.manage')->name('store.update');

        /*
         * The catalogue, search-first. Proposing a product is one screen
         * deeper than searching for one on purpose: making "create new"
         * the default is how a marketplace ends up with eleven entries for
         * one kettle.
         */
        Route::get('products', [SellerCatalogueController::class, 'index'])
            ->middleware('seller.can:catalog.view')->name('products');
        Route::get('products/create', [SellerCatalogueController::class, 'create'])
            ->middleware('seller.can:catalog.manage')->name('products.create');
        Route::post('products', [SellerCatalogueController::class, 'store'])
            ->middleware('seller.can:catalog.manage')->name('products.store');
        // Correcting a proposal a moderator sent back. Scoped to this
        // seller's own proposals and to the states in which one is still
        // theirs — an accepted product belongs to the catalogue.
        Route::get('products/{product}/edit', [SellerCatalogueController::class, 'edit'])
            ->middleware('seller.can:catalog.manage')->name('products.edit');
        Route::patch('products/{product}', [SellerCatalogueController::class, 'update'])
            ->middleware('seller.can:catalog.manage')->name('products.update');

        Route::get('offers', [SellerOfferController::class, 'index'])
            ->middleware('seller.can:catalog.view')->name('offers');
        Route::get('offers/create/{product}', [SellerOfferController::class, 'create'])
            ->middleware('seller.can:catalog.manage')->name('offers.create');
        Route::post('offers/{product}', [SellerOfferController::class, 'store'])
            ->middleware('seller.can:catalog.manage')->name('offers.store');
        Route::patch('offers/{offer}', [SellerOfferController::class, 'update'])
            ->middleware('seller.can:catalog.manage')->name('offers.update');
        Route::post('offers/{offer}/status', [SellerOfferController::class, 'transition'])
            ->middleware('seller.can:catalog.manage')->name('offers.status');

        /*
         * Stock. Read and write are different permissions: a catalogue
         * manager lists products and a warehouse manager counts them, and
         * the role matrix keeps those apart.
         */
        Route::get('inventory', [SellerInventoryController::class, 'index'])
            ->middleware('seller.can:inventory.view')->name('inventory');
        Route::get('inventory/{offer}', [SellerInventoryController::class, 'show'])
            ->middleware('seller.can:inventory.view')->name('inventory.show');
        Route::post('inventory/{offer}/adjust', [SellerInventoryController::class, 'adjust'])
            ->middleware('seller.can:inventory.manage')->name('inventory.adjust');
        Route::post('inventory/{offer}/opening-stock', [SellerInventoryController::class, 'openingStock'])
            ->middleware('seller.can:inventory.manage')->name('inventory.opening');
        Route::patch('inventory/{offer}/threshold', [SellerInventoryController::class, 'threshold'])
            ->middleware('seller.can:inventory.manage')->name('inventory.threshold');

        /*
         * Orders, scoped by the model's own tenant scope as well as by
         * the permission. A seller sees their half of a marketplace order
         * and never another seller's.
         */
        Route::get('orders', [SellerOrderController::class, 'index'])
            ->middleware('seller.can:orders.view')->name('orders');
        Route::get('orders/{reference}', [SellerOrderController::class, 'show'])
            ->middleware('seller.can:orders.view')->name('orders.show');

        /*
         * Fulfilment writes.
         *
         * `orders.manage` throughout, which is the permission the role
         * matrix already gives to the people who run a warehouse — owner,
         * administrator and fulfilment manager — and withholds from
         * catalogue, inventory and finance roles. A separate
         * `fulfilment.manage` would have drawn the same line twice.
         *
         * Every one of these re-resolves the seller order and the parcel
         * through the tenant scope, so an identifier from another seller
         * reaches no action.
         */
        Route::prefix('orders/{reference}')->name('orders.')->group(function (): void {
            Route::post('confirm', [SellerFulfilmentController::class, 'confirm'])
                ->middleware('seller.can:orders.manage')->name('confirm');
            Route::post('process', [SellerFulfilmentController::class, 'process'])
                ->middleware('seller.can:orders.manage')->name('process');
            Route::post('shipments', [SellerFulfilmentController::class, 'createShipment'])
                ->middleware('seller.can:orders.manage')->name('shipments.store');
            Route::post('shipments/{shipment}/tracking', [SellerFulfilmentController::class, 'updateTracking'])
                ->middleware('seller.can:orders.manage')->name('shipments.tracking');
            Route::post('shipments/{shipment}/ship', [SellerFulfilmentController::class, 'ship'])
                ->middleware('seller.can:orders.manage')->name('shipments.ship');
            Route::post('shipments/{shipment}/deliver', [SellerFulfilmentController::class, 'deliver'])
                ->middleware('seller.can:orders.manage')->name('shipments.deliver');
            Route::post('issues', [SellerFulfilmentController::class, 'reportIssue'])
                ->middleware('seller.can:orders.manage')->name('issues.store');
        });

        /*
         * Money.
         *
         * `finance.view` reads the statement; `payouts.view` reads where
         * the money went; `payouts.request` asks for it, and only the
         * owner holds that. Changing the destination is its own
         * permission again and asks for a password inside the action —
         * the three are different acts of trust and the route matrix says
         * so rather than hiding a button.
         */
        Route::get('earnings', [SellerFinanceController::class, 'earnings'])
            ->middleware('seller.can:finance.view')->name('earnings');

        Route::prefix('payouts')->name('payouts.')->group(function (): void {
            Route::get('/', [SellerFinanceController::class, 'index'])
                ->middleware('seller.can:payouts.view')->name('index');
            Route::post('/', [SellerFinanceController::class, 'store'])
                ->middleware(['seller.can:payouts.request', 'throttle:20,1'])->name('store');
            Route::post('destination', [SellerFinanceController::class, 'saveDestination'])
                ->middleware(['seller.can:payouts.account.manage', 'throttle:10,1'])->name('destination');
            Route::get('{reference}', [SellerFinanceController::class, 'show'])
                ->middleware('seller.can:payouts.view')->name('show');
            Route::post('{reference}/cancel', [SellerFinanceController::class, 'cancel'])
                ->middleware(['seller.can:payouts.request', 'throttle:20,1'])->name('cancel');
        });

        /*
         * The store's own performance. A different permission from
         * finance.view: seeing that a listing gets traffic and no orders
         * is a catalogue question, and does not require seeing what the
         * store earned.
         */
        Route::get('analytics', [SellerAnalyticsController::class, 'index'])
            ->middleware('seller.can:analytics.view')->name('analytics');

        Route::get('team', [SellerTeamController::class, 'index'])
            ->middleware('seller.can:members.view')->name('team');
        Route::post('team/invitations', [SellerTeamController::class, 'store'])
            ->middleware('seller.can:members.manage')->name('team.invite');
        Route::delete('team/invitations/{invitation}', [SellerTeamController::class, 'revoke'])
            ->middleware('seller.can:members.manage')->name('team.revoke');
        Route::patch('team/{membership}', [SellerTeamController::class, 'update'])
            ->middleware('seller.can:members.manage')->name('team.role');
        Route::delete('team/{membership}', [SellerTeamController::class, 'destroy'])
            ->middleware('seller.can:members.manage')->name('team.remove');
    });
});
