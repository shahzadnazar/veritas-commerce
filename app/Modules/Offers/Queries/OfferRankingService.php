<?php

declare(strict_types=1);

namespace App\Modules\Offers\Queries;

use App\Modules\Offers\Models\Offer;
use Illuminate\Support\Collection;

/**
 * Which offer leads on a product page, and in what order the rest follow.
 *
 * M2 ranks by price, then condition, then age, then id. That is
 * deliberately simple and completely deterministic — the same set of
 * offers always produces the same order, which is what makes the page
 * cacheable and a support question answerable.
 *
 * It lives behind this seam because ranking is where a marketplace's
 * commercial policy eventually goes: seller rating, dispatch speed, return
 * rate, fulfilment method, sponsored placement. Every one of those is a
 * change here and nowhere else. Burying "order by price" in a controller,
 * a Blade template and a search query is how three surfaces end up
 * disagreeing about which offer is the best one.
 */
final class OfferRankingService
{
    /**
     * @param  Collection<int, Offer>  $offers  eligible offers only
     * @return Collection<int, Offer>
     */
    public function rank(Collection $offers): Collection
    {
        // One comparator returning a tuple, compared element by element:
        // cheapest first, then the better condition at the same price,
        // then the faster dispatch, then the oldest listing — so the order
        // is stable rather than arbitrary when everything else ties.
        return $offers
            ->sortBy(fn (Offer $offer): array => [
                $offer->price_minor,
                $offer->condition->rank(),
                $offer->handling_days,
                $offer->id,
            ])
            ->values();
    }

    /**
     * The offer a customer is shown by default, if any qualifies.
     *
     * @param  Collection<int, Offer>  $offers
     */
    public function featured(Collection $offers): ?Offer
    {
        return $this->rank($offers)->first();
    }

    /**
     * The span of prices across a set of offers.
     *
     * @param  Collection<int, Offer>  $offers
     * @return array{from: int, to: int}|null
     */
    public function priceRange(Collection $offers): ?array
    {
        if ($offers->isEmpty()) {
            return null;
        }

        return [
            'from' => (int) $offers->min('price_minor'),
            'to' => (int) $offers->max('price_minor'),
        ];
    }
}
