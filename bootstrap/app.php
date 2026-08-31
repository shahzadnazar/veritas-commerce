<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureAdminPermission;
use App\Http\Middleware\EnsureSellerMembership;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireAdminTwoFactor;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        // Three route files, one per audience, so each area's middleware
        // and prefix are declared in one place rather than interleaved.
        web: __DIR__.'/../routes/storefront.php',
        then: function (): void {
            Illuminate\Support\Facades\Route::middleware('web')
                ->group(base_path('routes/seller.php'));

            Illuminate\Support\Facades\Route::middleware('web')
                ->group(base_path('routes/admin.php'));
        },
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);

        $middleware->alias([
            'seller' => EnsureSellerMembership::class,
            'admin.can' => EnsureAdminPermission::class,
            'admin.mfa' => RequireAdminTwoFactor::class,
        ]);

        // A guest on an admin route is sent to the staff sign-in page, not
        // the customer one — the two realms never hand off to each other.
        $middleware->redirectGuestsTo(
            fn (Request $request): string => $request->is('admin', 'admin/*')
                ? route('admin.login')
                : route('login'),
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
