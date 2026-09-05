<?php

declare(strict_types=1);

namespace App\Modules\Recommendations\Queries;

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Media\Contracts\ObjectStore;
use App\Modules\Media\Enums\Visibility;
use App\Modules\Recommendations\Data\RecommendedProduct;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The one gate every recommendation passes through.
 *
 * §30 and §31 are enforced here and nowhere else. A strategy produces
 * candidate product ids — a rough, cheap, possibly stale list — and this
 * turns them into products a customer may actually be shown. Because there
 * is a single implementation, adding a strategy cannot introduce a way to
 * recommend something unpublished: the new strategy hands its ids here
 * like every other one, or it produces nothing a page can render.
 *
 * Three things happen, in this order:
 *
 *   1. **Deduplication.** Candidate ids collapse to one row per product.
 *      This is §30: three sellers offering the same thing is three offers
 *      and one product, and a shelf that showed it three times would be
 *      showing the marketplace's internals to a shopper.
 *
 *   2. **Eligibility.** A product must be publicly visible *now*, by the
 *      catalogue's own rule — published and unmerged, read live from the
 *      products table — and it must have a public search document. Both,
 *      not either: the live row is what stops a stale index leaking a
 *      product that was withdrawn an hour ago, and the document is what
 *      supplies the price, image and availability a card needs. A product
 *      published thirty seconds ago and not yet indexed is simply not
 *      recommended yet, which is the right answer — a card with no price
 *      is worse than one fewer card.
 *
 *   3. **Order.** The caller's order is preserved exactly. A strategy that
 *      ranked its candidates has its ranking respected; this query sorts
 *      nothing of its own, because a gate that reordered results would
 *      make every strategy's ranking a suggestion.
 */
final class EligibleRecommendationProducts
{
    public function __construct(private readonly ObjectStore $objects) {}

    /**
     * @param  array<int, int>  $candidateIds  in the order the strategy ranked them
     * @param  array<int, int>  $excludeIds
     * @return array<int, RecommendedProduct>
     */
    public function __invoke(array $candidateIds, int $limit, array $excludeIds = []): array
    {
        $ordered = $this->distinctOrder($candidateIds, $excludeIds);

        if ($ordered === [] || $limit < 1) {
            return [];
        }

        $rows = $this->fetch($ordered);
        $products = [];

        foreach ($ordered as $productId) {
            if (! isset($rows[$productId])) {
                // Ineligible, unindexed, or deleted between the strategy
                // running and this query. Skipped silently: a shelf that
                // is one product short is not an error.
                continue;
            }

            $products[] = $this->toProduct($rows[$productId]);

            if (count($products) >= $limit) {
                break;
            }
        }

        return $products;
    }

    /**
     * Which of these ids a customer may be shown, without building cards.
     *
     * Used by the invariant tests and by the rebuild command's own
     * assertions, where the answer wanted is a set rather than a payload.
     *
     * @param  array<int, int>  $candidateIds
     * @return array<int, int>
     */
    public function filterIds(array $candidateIds): array
    {
        $ordered = $this->distinctOrder($candidateIds, []);

        if ($ordered === []) {
            return [];
        }

        $eligible = $this->fetch($ordered);

        return array_values(array_filter(
            $ordered,
            static fn (int $id): bool => isset($eligible[$id]),
        ));
    }

    /**
     * Distinct ids, first appearance wins, minus the exclusions.
     *
     * First appearance rather than last because a strategy chain puts its
     * strongest evidence first: a product that both "bought together" and
     * "same category" produced belongs where the stronger strategy put it.
     *
     * @param  array<int, int>  $candidateIds
     * @param  array<int, int>  $excludeIds
     * @return array<int, int>
     */
    private function distinctOrder(array $candidateIds, array $excludeIds): array
    {
        $excluded = array_flip(array_map(intval(...), $excludeIds));
        $seen = [];
        $ordered = [];

        foreach ($candidateIds as $candidate) {
            $id = (int) $candidate;

            if ($id < 1 || isset($seen[$id]) || isset($excluded[$id])) {
                continue;
            }

            $seen[$id] = true;
            $ordered[] = $id;
        }

        return $ordered;
    }

    /**
     * @param  array<int, int>  $productIds
     * @return array<int, stdClass>
     */
    private function fetch(array $productIds): array
    {
        $rows = DB::table('product_search_documents as d')
            ->join('products as p', 'p.id', '=', 'd.product_id')
            ->leftJoin('product_rating_summaries as r', 'r.product_id', '=', 'd.product_id')
            ->whereIn('d.product_id', $productIds)
            ->where('d.is_public', true)
            ->where('p.status', ProductStatus::Published->value)
            ->whereNull('p.merged_into_product_id')
            // A product nobody lists has no price to quote. This can only
            // ever remove candidates, never admit one, so the eligibility
            // rule above still holds in full.
            ->where('d.offer_count', '>', 0)
            ->select([
                'd.product_id', 'd.slug', 'd.title', 'd.brand_name',
                'd.lowest_price_minor', 'd.highest_price_minor', 'd.currency',
                'd.offer_count', 'd.in_stock',
                'd.primary_image_disk', 'd.primary_image_path', 'd.primary_image_alt',
                'r.rating_average', 'r.published_review_count',
            ])
            ->get();

        $keyed = [];

        foreach ($rows as $row) {
            $keyed[(int) $row->product_id] = $row;
        }

        return $keyed;
    }

    private function toProduct(stdClass $row): RecommendedProduct
    {
        $lowest = $row->lowest_price_minor === null ? null : (int) $row->lowest_price_minor;
        $highest = $row->highest_price_minor === null ? null : (int) $row->highest_price_minor;
        $currency = is_string($row->currency) ? $row->currency : null;
        $average = $row->rating_average === null ? null : (float) $row->rating_average;

        return new RecommendedProduct(
            productId: (int) $row->product_id,
            slug: (string) $row->slug,
            title: (string) $row->title,
            brandName: is_string($row->brand_name) ? $row->brand_name : null,
            imageUrl: $this->imageUrl($row),
            imageAlt: is_string($row->primary_image_alt) ? $row->primary_image_alt : null,
            displayPrice: RecommendedProduct::formatPrice($lowest, $highest, $currency),
            hasPriceRange: $lowest !== null && $highest !== null && $highest > $lowest,
            offerCount: (int) $row->offer_count,
            inStock: (bool) $row->in_stock,
            ratingAverage: $average,
            ratingCount: (int) ($row->published_review_count ?? 0),
            reason: '',
        );
    }

    private function imageUrl(stdClass $row): ?string
    {
        if (! is_string($row->primary_image_path) || $row->primary_image_path === '') {
            return null;
        }

        $disk = is_string($row->primary_image_disk) ? $row->primary_image_disk : '';

        return $this->objects->url($this->objects->fromReference(
            $disk.':'.$row->primary_image_path,
            Visibility::Public,
        ));
    }
}
