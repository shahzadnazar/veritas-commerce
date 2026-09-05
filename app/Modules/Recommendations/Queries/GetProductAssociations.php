<?php

declare(strict_types=1);

namespace App\Modules\Recommendations\Queries;

use App\Modules\Recommendations\Enums\AssociationKind;
use Illuminate\Support\Facades\DB;

/**
 * Reads the co-occurrence projection.
 *
 * The support threshold is applied *here*, when reading, not when the
 * projection is built — which is why the rebuild stores every pair it
 * saw with its support count. Lowering the threshold later is then a
 * configuration change rather than a rebuild, and raising it cannot lose
 * data that would have to be recomputed to get back.
 */
final class GetProductAssociations
{
    /**
     * @return array<int, int> associated product ids, strongest first
     */
    public function __invoke(int $productId, AssociationKind $kind, int $limit): array
    {
        if ($limit < 1) {
            return [];
        }

        return DB::table('product_associations')
            ->where('product_id', $productId)
            ->where('kind', $kind->value)
            ->where('support', '>=', $kind->minimumSupport())
            // Score first, then id: without the tiebreak two products with
            // equal support would swap places between page loads, and a
            // shelf that reshuffles on refresh looks broken.
            ->orderByDesc('score')
            ->orderBy('associated_product_id')
            ->limit($limit)
            ->pluck('associated_product_id')
            ->map(intval(...))
            ->all();
    }

    /**
     * The same lookup for several anchors at once, merged by score.
     *
     * A cart shelf asks "what goes with these five things", and five
     * separate queries would rank each anchor's partners against nothing.
     *
     * @param  array<int, int>  $productIds
     * @return array<int, int>
     */
    public function forMany(array $productIds, AssociationKind $kind, int $limit): array
    {
        $ids = array_values(array_unique(array_map(intval(...), $productIds)));

        if ($ids === [] || $limit < 1) {
            return [];
        }

        return DB::table('product_associations')
            ->whereIn('product_id', $ids)
            ->where('kind', $kind->value)
            ->where('support', '>=', $kind->minimumSupport())
            ->whereNotIn('associated_product_id', $ids)
            ->groupBy('associated_product_id')
            ->orderByRaw('sum(score) desc')
            ->orderBy('associated_product_id')
            ->limit($limit)
            ->pluck('associated_product_id')
            ->map(intval(...))
            ->all();
    }
}
