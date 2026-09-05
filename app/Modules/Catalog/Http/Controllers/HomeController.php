<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Events\Support\AnonymousSession;
use App\Modules\Offers\Enums\OfferStatus;
use App\Modules\Offers\Models\Offer;
use App\Modules\Recommendations\Data\RecommendationRequest;
use App\Modules\Recommendations\Enums\RecommendationSlot;
use App\Modules\Recommendations\RecommendationService;
use App\Modules\Sellers\Enums\SellerStatus;
use App\Modules\Sellers\Models\SellerAccount;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controllers orchestrate. They validate, authorise, call a query or an
 * action, and hand the result to a view — no business rules live here.
 */
final class HomeController
{
    public function __construct(private readonly RecommendationService $recommendations) {}

    public function __invoke(Request $request): Response
    {
        $viewer = $request->user('web');

        return Inertia::render('Home', [
            'stats' => [
                'products' => Offer::query()->where('status', OfferStatus::Published->value)->count(),
                'sellers' => SellerAccount::query()->where('status', SellerStatus::Approved->value)->count(),
            ],
            /*
             * Three shelves, each excluding what the ones above it already
             * showed, so the home page cannot put the same product in
             * front of somebody three times.
             *
             * Two of them are personal and are therefore never cached
             * across visitors — RecommendationService reads that from the
             * slot rather than taking a flag, so a page cannot get it
             * wrong. An anonymous visitor's session still gives them a
             * "recently viewed" shelf; a signed-out visitor with no
             * history gets neither, and the shelves simply do not render.
             */
            'shelves' => $this->recommendations->shelves(
                [
                    RecommendationSlot::RecentlyViewed,
                    RecommendationSlot::ForYou,
                    RecommendationSlot::Trending,
                ],
                new RecommendationRequest(
                    slot: RecommendationSlot::Trending,
                    userId: $viewer === null ? null : (int) $viewer->getAuthIdentifier(),
                    anonymousSessionId: AnonymousSession::idFor($request),
                ),
            ),
        ]);
    }
}
