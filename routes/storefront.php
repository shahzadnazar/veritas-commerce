<?php

declare(strict_types=1);

use App\Modules\Cart\Http\Controllers\CartController;
use App\Modules\Catalog\Http\Controllers\HomeController;
use App\Modules\Catalog\Http\Controllers\PublicCategoryController;
use App\Modules\Catalog\Http\Controllers\PublicProductController;
use App\Modules\Catalog\Http\Controllers\SearchController;
use App\Modules\Catalog\Http\Controllers\SitemapController;
use App\Modules\Checkout\Http\Controllers\CheckoutController;
use App\Modules\Checkout\Http\Controllers\OrderPaymentController;
use App\Modules\Checkout\Http\Controllers\PaymentPendingController;
use App\Modules\Customers\Http\Controllers\WishlistController;
use App\Modules\Identity\Http\Controllers\EmailVerificationController;
use App\Modules\Identity\Http\Controllers\LoginController;
use App\Modules\Identity\Http\Controllers\PasswordResetController;
use App\Modules\Identity\Http\Controllers\ProfileController;
use App\Modules\Identity\Http\Controllers\RegisterController;
use App\Modules\Orders\Http\Controllers\CustomerOrderController;
use App\Modules\Recommendations\Http\Controllers\RecommendationTrackingController;
use App\Modules\Reviews\Http\Controllers\ProductReviewController;
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

/*
 * Recommendation telemetry. Open to signed-out visitors because most
 * browsing is signed out, and rate-limited because a beacon endpoint is
 * the easiest thing on a storefront to point a script at.
 */
Route::post('/recommendations/impressions', [RecommendationTrackingController::class, 'impressions'])
    ->middleware('throttle:120,1')
    ->name('recommendations.impressions');
Route::post('/recommendations/clicks', [RecommendationTrackingController::class, 'clicks'])
    ->middleware('throttle:120,1')
    ->name('recommendations.clicks');

/*
 * The basket and the checkout.
 *
 * Open to anyone, signed in or not: a marketplace that made a shopper
 * register before they could see what a purchase costs would lose most of
 * them at that step. Ownership comes from the session throughout, so none
 * of these routes takes an identifier that could point at somebody else's
 * cart.
 *
 * All of them are noindex — see Indexability::privatePaths().
 */
Route::get('cart', [CartController::class, 'show'])->name('cart');
Route::post('cart', [CartController::class, 'store'])->middleware('throttle:60,1')->name('cart.store');
Route::patch('cart/{line}', [CartController::class, 'update'])->middleware('throttle:120,1')->name('cart.update');
Route::delete('cart/{line}', [CartController::class, 'destroy'])->name('cart.destroy');

Route::get('checkout', [CheckoutController::class, 'show'])->name('checkout');
// Throttled harder than the cart: this is the route that takes inventory
// off the shelf, and a loop hitting it would hold a seller's whole stock.
Route::post('checkout', [CheckoutController::class, 'store'])->middleware('throttle:20,1')->name('checkout.store');
Route::get('checkout/{reference}/payment', PaymentPendingController::class)->name('checkout.payment');

/*
 * The payment itself.
 *
 * Neither endpoint can mark anything paid — `prepare` asks the provider
 * for a payment whose amount comes from the order, and `status` reports
 * what verified provider events already wrote. PaymentAuthorityTest fails
 * the build if any route reaches the paid transition.
 *
 * Ownership is resolved per request from the session, so the reference in
 * the URL is never on its own enough. Preparation is throttled harder than
 * polling: it is the one that talks to Stripe.
 */
Route::post('checkout/{reference}/payment/prepare', [OrderPaymentController::class, 'prepare'])
    ->middleware('throttle:20,1')
    ->name('checkout.payment.prepare');

Route::get('checkout/{reference}/payment/status', [OrderPaymentController::class, 'status'])
    ->middleware('throttle:120,1')
    ->name('checkout.payment.status');

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

    /*
     * Order history. Scoped to the signed-in customer inside the
     * controller as well as here: a reference is a lookup key, never an
     * authorization, and a guessed one must 404 rather than 403.
     */
    Route::get('account/orders', [CustomerOrderController::class, 'index'])->name('account.orders');
    Route::get('account/orders/{reference}', [CustomerOrderController::class, 'show'])
        ->name('account.orders.show');

    Route::get('account', [ProfileController::class, 'show'])->name('account');
    Route::put('account', [ProfileController::class, 'update'])->name('account.update');
    Route::put('account/password', [ProfileController::class, 'updatePassword'])->name('account.password');

    /*
     * The wishlist. Behind `auth` because it is personal, and every action
     * takes the customer from the session rather than the request body —
     * there is no parameter to change to reach somebody else's list.
     */
    Route::get('account/wishlist', [WishlistController::class, 'index'])->name('account.wishlist');
    Route::post('account/wishlist', [WishlistController::class, 'store'])
        ->middleware('throttle:60,1')
        ->name('account.wishlist.store');
    Route::delete('account/wishlist', [WishlistController::class, 'destroy'])
        ->middleware('throttle:60,1')
        ->name('account.wishlist.destroy');

    /*
     * Reviews. Writing one requires a delivered purchase, which
     * SubmitReview establishes from the order record — never from
     * anything the browser sends.
     */
    Route::post('reviews', [ProductReviewController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('reviews.store');
    Route::put('reviews/{review}', [ProductReviewController::class, 'update'])
        ->middleware('throttle:20,1')
        ->name('reviews.update');
    Route::delete('reviews/{review}', [ProductReviewController::class, 'destroy'])
        ->name('reviews.destroy');
});
