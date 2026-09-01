<?php

declare(strict_types=1);

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
