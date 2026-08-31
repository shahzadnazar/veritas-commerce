<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\Identity\Models\AdminUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * MFA is mandatory for staff, so an account that has not finished
 * enrolment can reach the enrolment routes and nothing else.
 *
 * This is what stops the requirement being merely advisory: without it, an
 * administrator could skip setup and keep working indefinitely.
 */
final class RequireAdminTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user('admin');

        if (! $admin instanceof AdminUser) {
            return redirect()->guest(route('admin.login'));
        }

        if ($admin->hasTwoFactorEnabled()) {
            return $next($request);
        }

        if ($request->routeIs('admin.mfa.*', 'admin.logout')) {
            return $next($request);
        }

        return redirect()->route('admin.mfa.setup');
    }
}
