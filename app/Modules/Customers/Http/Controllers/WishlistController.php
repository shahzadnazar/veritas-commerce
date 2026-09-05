<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers;

use App\Modules\Customers\Actions\RemoveFromWishlist;
use App\Modules\Customers\Actions\SaveToWishlist;
use App\Modules\Customers\Data\WishlistEntry;
use App\Modules\Customers\Queries\GetWishlist;
use App\Modules\Events\Actions\RecordInteraction;
use App\Modules\Recommendations\Data\RecommendationRequest;
use App\Modules\Recommendations\Enums\RecommendationSlot;
use App\Modules\Recommendations\RecommendationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * The customer's saved products.
 *
 * Every route is behind `auth`, and every one derives the user id from the
 * session rather than from the request body — so there is no parameter a
 * customer could change to read or edit somebody else's list.
 *
 * The page is deliberately `noindex`: a wishlist is personal, and a search
 * engine that reached one has been given something it should never have
 * had. §100 in one meta tag.
 */
final class WishlistController
{
    public function __construct(
        private readonly GetWishlist $wishlist,
        private readonly SaveToWishlist $save,
        private readonly RemoveFromWishlist $remove,
        private readonly RecommendationService $recommendations,
        private readonly RecordInteraction $interactions,
    ) {}

    public function index(Request $request): Response
    {
        $userId = $this->userId($request);

        $entries = ($this->wishlist)($userId);
        $savedIds = array_map(
            static fn (WishlistEntry $entry): int => $entry->productId,
            $entries,
        );

        return Inertia::render('Account/Wishlist', [
            'items' => array_map(
                static fn (WishlistEntry $entry): array => $entry->toArray(),
                $entries,
            ),
            // A shelf under an empty wishlist is the difference between a
            // dead end and a starting point.
            'suggestions' => $this->recommendations->for(new RecommendationRequest(
                slot: RecommendationSlot::ForYou,
                userId: $userId,
                limit: 8,
                excludeProductIds: $savedIds,
            ))->toArray(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $userId = $this->userId($request);
        $productId = $this->productId($request);

        try {
            ($this->save)($userId, $productId);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['product' => $exception->getMessage()]);
        }

        $this->interactions->wishlistAdded($request, $productId);

        return back()->with('status', 'Saved to your wishlist.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $userId = $this->userId($request);
        $productId = $this->productId($request);

        if (($this->remove)($userId, $productId)) {
            $this->interactions->wishlistRemoved($request, $productId);
        }

        return back()->with('status', 'Removed from your wishlist.');
    }

    private function userId(Request $request): int
    {
        return (int) $request->user('web')?->getAuthIdentifier();
    }

    /**
     * The product being saved, resolved from its public id.
     *
     * Public ids everywhere a customer can see them, so a sequential
     * database id never leaks the size of the catalogue — and a guessed
     * one resolves to nothing rather than to the next product along.
     */
    private function productId(Request $request): int
    {
        $validated = $request->validate([
            'product' => ['required', 'string', 'max:64'],
        ]);

        $productId = DB::table('products')
            ->where('public_id', $validated['product'])
            ->value('id');

        if ($productId === null) {
            throw ValidationException::withMessages(['product' => 'That product could not be found.']);
        }

        return (int) $productId;
    }
}
