<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\Identity\Enums\AdminPermission;
use App\Modules\Identity\Models\AdminUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Defence in depth over the per-action policies.
 *
 * Hiding a sidebar item is a courtesy; this and the policies are the
 * control. Both run — a route reachable without either is a bug the
 * route×role test should catch.
 */
final class EnsureAdminPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        /** @var AdminUser|null $admin */
        $admin = $request->user('admin');

        if ($admin === null) {
            return redirect()->guest('/admin/login');
        }

        $required = AdminPermission::tryFrom($permission);

        if ($required === null || ! $admin->role->can($required)) {
            abort(403);
        }

        return $next($request);
    }
}
