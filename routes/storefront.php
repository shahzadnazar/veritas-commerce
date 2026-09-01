<?php

declare(strict_types=1);

use App\Modules\Catalog\Http\Controllers\HomeController;
use App\Modules\Catalog\Http\Controllers\PublicCategoryController;
use App\Modules\Catalog\Http\Controllers\PublicProductController;
use App\Modules\Catalog\Http\Controllers\SearchController;
use App\Modules\Catalog\Http\Controllers\SitemapController;
use App\Modules\Identity\Http\Controllers\EmailVerificationController;
use App\Modules\Identity\Http\Controllers\LoginController;
use App\Modules\Identity\Http\Controllers\PasswordResetController;
use App\Modules\Identity\Http\Controllers\ProfileController;
use App\Modules\Identity\Http\Controllers\RegisterController;
use App\Modules\Stores\Http\Controllers\PublicStoreController;
use Illuminate\Support\Facades\Route;

/*
 * The customer storefront — server-rendered, indexable, public.
 */
Route::get('/', HomeController::class)->name('home');

/*
 * Sitemaps and robots.
 *
 * An index plus one file per kind: a single flat file works today and
 * would have to be split the first time the catalogue outgrows it.
 * Only publicly eligible URLs appear — a sitemap listing a suspended
 * product is telling a crawler to fetch a 404.
 */
Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');
Route::get('/products-sitemap.xml', [SitemapController::class, 'products'])->name('sitemap.products');
Route::get('/categories-sitemap.xml', [SitemapController::class, 'categories'])->name('sitemap.categories');
Route::get('/stores-sitemap.xml', [SitemapController::class, 'stores'])->name('sitemap.stores');
Route::get('/robots.txt', [SitemapController::class, 'robots'])->name('robots');

// The public seller store. Only an eligible store resolves; everything
// else is a 404 rather than an empty shell, so a suspended store leaves no
// trace in search results.
Route::get('/stores/{slug}', PublicStoreController::class)->name('stores.show');

/*
 * The canonical product page: one per product, never one per offer. Four
 * sellers listing the same kettle must not become four competing pages
 * splitting the authority of one.
 */
Route::get('/products/{slug}', PublicProductController::class)->name('products.show');

/*
 * Search. Never indexable — a search URL records what one person typed
 * once, and letting crawlers enumerate that space produces infinite thin
 * pages competing with the category pages that should rank.
 */
Route::get('/search', SearchController::class)->name('search');
Route::get('/search/suggestions', [SearchController::class, 'suggestions'])
    ->middleware('throttle:60,1')->name('search.suggestions');
Route::post('/search/click', [SearchController::class, 'click'])
    ->middleware('throttle:120,1')->name('search.click');
Route::get('/categories/{slug}', PublicCategoryController::class)->name('categories.show');

Route::middleware('guest')->group(function (): void {
    Route::get('register', [RegisterController::class, 'show'])->name('register');
    Route::post('register', [RegisterController::class, 'store']);

    Route::get('login', [LoginController::class, 'show'])->name('login');
    Route::post('login', [LoginController::class, 'store']);

    Route::get('forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('forgot-password', [PasswordResetController::class, 'email'])->name('password.email');
    Route::get('reset-password/{token}', [PasswordResetController::class, 'reset'])->name('password.reset');
    Route::post('reset-password', [PasswordResetController::class, 'update'])->name('password.update');
});

Route::middleware('auth')->group(function (): void {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('verify-email', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::get('verify-email/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');
    Route::post('verify-email/send', [EmailVerificationController::class, 'send'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    Route::get('account', [ProfileController::class, 'show'])->name('account');
    Route::put('account', [ProfileController::class, 'update'])->name('account.update');
    Route::put('account/password', [ProfileController::class, 'updatePassword'])->name('account.password');
});
