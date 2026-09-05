<?php

declare(strict_types=1);

namespace App\Modules\Recommendations\Support;

use Illuminate\Support\Facades\DB;

/**
 * What the strategies need to know about the product being viewed.
 *
 * Read once from the search document rather than by hydrating a Product,
 * because every field here is already denormalised there and a shelf that
 * costs an extra eager-loaded model per render is a shelf that gets turned
 * off in production.
 */
final readonly class AnchorProfile
{
    /** @param  array<int, int>  $categoryAncestorIds */
    private function __construct(
        public int $productId,
        public ?int $categoryId,
        public ?int $brandId,
        public ?int $lowestPriceMinor,
        public array $categoryAncestorIds,
    ) {}

    public static function for(int $productId): ?self
    {
        $row = DB::table('product_search_documents')
            ->where('product_id', $productId)
            ->select(['product_id', 'category_id', 'brand_id', 'lowest_price_minor', 'category_ancestor_ids'])
            ->first();

        if ($row === null) {
            return null;
        }

        return new self(
            productId: (int) $row->product_id,
            categoryId: $row->category_id === null ? null : (int) $row->category_id,
            brandId: $row->brand_id === null ? null : (int) $row->brand_id,
            lowestPriceMinor: $row->lowest_price_minor === null ? null : (int) $row->lowest_price_minor,
            categoryAncestorIds: self::parseIntArray($row->category_ancestor_ids),
        );
    }

    /**
     * The price window a comparable sits in.
     *
     * A "similar product" three times the price is not a comparison, it is
     * an upsell wearing a comparison's clothes. Returns null when the
     * anchor has no price at all, in which case the caller simply does not
     * filter on price rather than filtering on a guess.
     *
     * @return array{0: int, 1: int}|null
     */
    public function priceBand(): ?array
    {
        if ($this->lowestPriceMinor === null || $this->lowestPriceMinor < 1) {
            return null;
        }

        $percent = config('veritas.recommendations.price_band_percent');
        $percent = is_numeric($percent) ? (int) $percent : 35;
        $percent = max(1, min(100, $percent));

        $spread = intdiv($this->lowestPriceMinor * $percent, 100);

        return [max(0, $this->lowestPriceMinor - $spread), $this->lowestPriceMinor + $spread];
    }

    /**
     * The category lineage to search within, narrowest first.
     *
     * Falls back to the direct category when the document carries no
     * ancestry, and to nothing when the product is uncategorised — in
     * which case a category strategy correctly declines to answer rather
     * than matching the entire catalogue.
     *
     * @return array<int, int>
     */
    public function categoryLineage(): array
    {
        if ($this->categoryAncestorIds !== []) {
            return $this->categoryAncestorIds;
        }

        return $this->categoryId === null ? [] : [$this->categoryId];
    }

    /**
     * PostgreSQL hands a bigint[] back as its literal text form.
     *
     * @return array<int, int>
     */
    private static function parseIntArray(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_map(intval(...), $value));
        }

        if (! is_string($value)) {
            return [];
        }

        $inner = trim($value, '{}');

        if ($inner === '') {
            return [];
        }

        return array_map(intval(...), explode(',', $inner));
    }
}
