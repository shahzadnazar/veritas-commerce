<?php

declare(strict_types=1);

namespace App\Modules\Recommendations\Strategies;

use App\Modules\Recommendations\Contracts\RecommendationStrategy;
use App\Modules\Recommendations\Data\RecommendationRequest;
use App\Modules\Recommendations\Support\AnchorProfile;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The honest comparison set: same shelf, comparable money.
 *
 * Content-based rather than behavioural, so it works on the first day the
 * catalogue exists — which is exactly when the co-occurrence strategies
 * have nothing. Three things decide the ranking, in this order:
 *
 *   - the category, narrowest first, because a phone case belongs with
 *     phone cases and not merely with electronics;
 *   - the brand, as a bonus rather than a filter, because somebody looking
 *     at one make usually wants to see the others;
 *   - the price, as a band around the anchor, because a "similar product"
 *     at four times the price is an upsell, not a comparison.
 *
 * The band is a preference, not a hard filter: a category with three
 * products in it should still show them. Products outside the band rank
 * below every product inside it and are only reached when the band cannot
 * fill the shelf.
 */
final class SimilarProductStrategy implements RecommendationStrategy
{
    public function key(): string
    {
        return 'similar_product';
    }

    public function supports(RecommendationRequest $request): bool
    {
        return $request->anchorProductId !== null;
    }

    /** @return array<int, int> */
    public function candidates(RecommendationRequest $request): array
    {
        if ($request->anchorProductId === null) {
            return [];
        }

        $anchor = AnchorProfile::for($request->anchorProductId);

        if ($anchor === null) {
            return [];
        }

        $lineage = $anchor->categoryLineage();

        if ($lineage === [] && $anchor->brandId === null) {
            // Uncategorised and unbranded: there is nothing to be similar
            // to. Declining is right — the alternative is "every product
            // in the catalogue", which is not a comparison set.
            return [];
        }

        $band = $anchor->priceBand();

        $query = DB::table('product_search_documents as d')
            ->where('d.is_public', true)
            ->where('d.product_id', '!=', $anchor->productId)
            ->where('d.offer_count', '>', 0);

        $this->constrainToNeighbourhood($query, $lineage, $anchor->brandId);

        // Three tie-breaks, applied in decreasing order of how much they
        // say about similarity. Each is a bound parameter rather than an
        // interpolated value: the SQL is a constant and the numbers are
        // data, which is the only arrangement that stays safe when
        // somebody later makes one of these configurable.
        if ($anchor->categoryId !== null) {
            $query->orderByRaw('case when d.category_id = ? then 1 else 0 end desc', [$anchor->categoryId]);
        }

        if ($band !== null) {
            $query->orderByRaw(
                'case when d.lowest_price_minor between ? and ? then 1 else 0 end desc',
                [$band[0], $band[1]],
            );
        }

        if ($anchor->brandId !== null) {
            $query->orderByRaw('case when d.brand_id = ? then 1 else 0 end desc', [$anchor->brandId]);
        }

        return $query
            ->select('d.product_id')
            ->orderByDesc('d.in_stock')
            ->orderBy('d.product_id')
            ->limit($request->candidateLimit())
            ->pluck('d.product_id')
            ->map(intval(...))
            ->all();
    }

    /**
     * The pool to rank within: the anchor's category lineage, or its brand
     * when it has no category.
     *
     * @param  array<int, int>  $lineage
     */
    private function constrainToNeighbourhood(Builder $query, array $lineage, ?int $brandId): void
    {
        if ($lineage === []) {
            $query->where('d.brand_id', $brandId);

            return;
        }

        $query->where(static function (Builder $inner) use ($lineage, $brandId): void {
            $inner->whereIn('d.category_id', $lineage)
                ->orWhereRaw('d.category_ancestor_ids && ?::bigint[]', ['{'.implode(',', $lineage).'}']);

            if ($brandId !== null) {
                $inner->orWhere('d.brand_id', $brandId);
            }
        });
    }
}
