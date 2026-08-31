<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\Sellers\Concerns\CurrentSeller;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The seller portal requires an accepted membership, and the seller it
 * scopes to is derived from that membership — never from the request.
 *
 * A suspended seller keeps access: they must still fulfil open orders.
 * What suspension takes away is publishing and payouts, enforced at those
 * actions rather than at the door.
 */
final class EnsureSellerMembership
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() === null) {
            return redirect()->guest('/login');
        }

        if (! CurrentSeller::isActing()) {
            abort(404);
        }

        return $next($request);
    }
}
