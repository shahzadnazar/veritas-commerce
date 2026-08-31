<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Offers\Enums\OfferStatus;
use App\Modules\Offers\Models\Offer;
use App\Modules\Sellers\Enums\SellerStatus;
use App\Modules\Sellers\Models\SellerAccount;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controllers orchestrate. They validate, authorise, call a query or an
 * action, and hand the result to a view — no business rules live here.
 */
final class HomeController
{
    public function __invoke(): Response
    {
        return Inertia::render('Home', [
            'stats' => [
                'products' => Offer::query()->where('status', OfferStatus::Published->value)->count(),
                'sellers' => SellerAccount::query()->where('status', SellerStatus::Approved->value)->count(),
            ],
        ]);
    }
}
