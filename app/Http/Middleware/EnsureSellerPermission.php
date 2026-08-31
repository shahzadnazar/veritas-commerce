<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\Sellers\Concerns\CurrentSeller;
use App\Modules\Sellers\Enums\SellerPermission;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-level capability check for the seller portal.
 *
 * The capability is resolved from the actor's membership, never from
 * anything in the request, and a suspended seller fails every write here
 * regardless of role.
 */
final class EnsureSellerPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $required = SellerPermission::tryFrom($permission);

        abort_if($required === null, 500, "Unknown seller permission: {$permission}");
        abort_unless(CurrentSeller::can($required), 403);

        return $next($request);
    }
}
