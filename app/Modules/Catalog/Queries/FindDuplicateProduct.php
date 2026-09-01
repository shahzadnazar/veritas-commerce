<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Queries;

use App\Modules\Catalog\Data\DuplicateMatch;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Support\CatalogueText;
use Illuminate\Database\Eloquent\Builder;

/**
 * Deterministic duplicate detection.
 *
 * No fuzzy matching and nothing statistical: a barcode either matches or
 * it does not, and a decision a moderator cannot explain to the seller it
 * affected is worse than no decision. The signals are ranked, and only the
 * identifier ones are treated as conclusive.
 *
 * A weak match does not refuse anything. It routes the proposal to a
 * moderator with the candidate attached, which is the point — nothing is
 * ever auto-merged on a resemblance.
 */
final class FindDuplicateProduct
{
    /**
     * @param  array<string, string|null>  $identifiers  gtin/upc/ean/isbn/mpn/model_number
     * @return array<int, DuplicateMatch>
     */
    public function __invoke(
        string $title,
        ?int $brandId,
        ?int $categoryId,
        array $identifiers = [],
        ?int $excludeProductId = null,
    ): array {
        $matches = [];

        // 1. A trade identifier is the strongest signal there is: two
        //    products sharing a barcode are the same product, and the
        //    check digit means a typo rarely collides by accident.
        foreach (['gtin', 'upc', 'ean', 'isbn'] as $kind) {
            $value = CatalogueText::normaliseIdentifier($identifiers[$kind] ?? null);

            if ($value === null) {
                continue;
            }

            $existing = $this->base($excludeProductId)->where($kind, $value)->first();

            if ($existing !== null) {
                $matches[] = new DuplicateMatch(
                    product: $existing,
                    signal: $kind,
                    isConclusive: true,
                    explanation: strtoupper($kind)." {$value} already identifies “{$existing->title}”.",
                );
            }
        }

        // 2. Brand plus manufacturer part number: not a barcode, but a
        //    manufacturer does not issue one part number for two products.
        $partNumber = $this->firstNonEmpty($identifiers, ['mpn', 'model_number']);

        if ($brandId !== null && $partNumber !== null) {
            $existing = $this->base($excludeProductId)
                ->where('brand_id', $brandId)
                ->where(function (Builder $query) use ($partNumber): void {
                    $query->where('mpn', $partNumber)->orWhere('model_number', $partNumber);
                })
                ->first();

            if ($existing !== null) {
                $matches[] = new DuplicateMatch(
                    product: $existing,
                    signal: 'brand_model',
                    isConclusive: true,
                    explanation: "The same brand already lists part number {$partNumber} as “{$existing->title}”.",
                );
            }
        }

        // 3. Same normalised title, same brand, same category. Suggestive
        //    only: two sellers may genuinely stock different things with
        //    the same plain name, so this goes to a person.
        $normalised = CatalogueText::normalise($title);

        if ($normalised !== '') {
            $existing = $this->base($excludeProductId)
                ->where('normalised_title', $normalised)
                ->when($brandId !== null, fn (Builder $query) => $query->where('brand_id', $brandId))
                ->when($categoryId !== null, fn (Builder $query) => $query->where('category_id', $categoryId))
                ->first();

            if ($existing !== null) {
                $matches[] = new DuplicateMatch(
                    product: $existing,
                    signal: 'title',
                    isConclusive: false,
                    explanation: "“{$existing->title}” already exists in this category with the same name.",
                );
            }
        }

        return $matches;
    }

    /** @param  array<int, DuplicateMatch>  $matches */
    public function conclusiveIn(array $matches): ?DuplicateMatch
    {
        foreach ($matches as $match) {
            if ($match->isConclusive) {
                return $match;
            }
        }

        return null;
    }

    /** @return Builder<Product> */
    private function base(?int $excludeProductId): Builder
    {
        return Product::query()
            // A merged product is not a candidate: its survivor is.
            ->whereNull('merged_into_product_id')
            ->when($excludeProductId !== null, fn (Builder $query) => $query->whereKeyNot($excludeProductId));
    }

    /**
     * @param  array<string, string|null>  $identifiers
     * @param  array<int, string>  $keys
     */
    private function firstNonEmpty(array $identifiers, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = trim((string) ($identifiers[$key] ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
