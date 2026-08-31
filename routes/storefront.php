<?php

declare(strict_types=1);

use App\Modules\Catalog\Http\Controllers\HomeController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
 * The customer storefront — server-rendered, indexable, public.
 *
 * M0 ships the shell. Catalogue, cart and checkout arrive in M2 and M3.
 */
Route::get('/', HomeController::class)->name('home');

// The sign-in shell. The credential flow itself is M1; the named route
// exists now because the seller portal's auth middleware redirects to it.
Route::get('/login', fn () => Inertia::render('Auth/Login'))->name('login');
