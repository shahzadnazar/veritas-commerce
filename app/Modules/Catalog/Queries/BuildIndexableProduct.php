<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Queries;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductAttributeValue;
use App\Modules\Offers\Models\Offer;
use App\Modules\Offers\Queries\OfferEligibility;
use App\Modules\Search\Contracts\IndexableProductSource;
use App\Modules\Search\Data\IndexableProduct;
use Illuminate\Support\Collection;

/**
 * Describes one product for the search index.
 *
 * Lives in the catalogue rather than in Search because describing a
 * product is the catalogue's job: an index that read these models itself
 * would become a second, quietly diverging definition of what a product
 * is. Search sees only the interface and the flat document.
 *
 * Prices, conditions and availability all come from the same eligibility
 * rule the storefront uses, so a product cannot appear in search at a
 * price nobody can buy it at, or in a condition no live offer is in.
 */
final class BuildIndexableProduct implements IndexableProductSource
{
    public function __construct(private readonly OfferEligibility $eligibility) {}

    public function describe(int $productId): ?IndexableProduct
    {
        $product = Product::query()
            ->with([
                'brand', 'category', 'media',
                'attributeValues.attribute', 'attributeValues.option',
            ])
            ->find($productId);

        if ($product === null || $product->merged_into_product_id !== null) {
            // Gone, or merged away: the survivor carries its traffic now.
            return null;
        }

        /** @var Collection<int, Offer> $offers */
        $offers = $this->eligibility->queryWithAvailability()
            ->where('offers.product_id', $product->id)
            ->get();

        $buyable = $offers->filter(static fn (Offer $offer): bool => (int) $offer->getAttribute('available_stock') > 0);
        $image = $product->primaryImage();
        $lineage = $product->category?->ancestorIds() ?: array_filter([$product->category_id]);

        return new IndexableProduct(
            productId: $product->id,
            title: $product->title,
            slug: $product->slug,
            description: $product->description,
            brandName: $product->brand->name ?? null,
            brandId: $product->brand_id,
            categoryId: $product->category_id,
            categoryPath: $this->categoryPath($product),
            categoryAncestorIds: array_values(array_map('intval', $lineage)),
            identifiers: array_values($product->identifiers()),
            specifications: $this->specifications($product),
            filterableAttributes: $this->filterableAttributes($product),
            conditions: $offers->pluck('condition')->map(
                static fn ($condition): string => is_string($condition) ? $condition : $condition->value,
            )->unique()->values()->all(),
            isPublic: $product->isPubliclyVisible(),
            lowestPriceMinor: $offers->isEmpty() ? null : (int) $offers->min('price_minor'),
            highestPriceMinor: $offers->isEmpty() ? null : (int) $offers->max('price_minor'),
            currency: $offers->isEmpty() ? null : (string) $offers->first()->currency,
            offerCount: $offers->count(),
            inStock: $buyable->isNotEmpty(),
            inStockOfferCount: $buyable->count(),
            imageDisk: $image?->disk,
            imagePath: $image?->path,
            imageAlt: $image?->alt_text,
            publishedAt: $product->published_at?->toDateTimeString(),
        );
    }

    /**
     * Everything the product says about itself, for keyword reach.
     *
     * @return array<string, string>
     */
    private function specifications(Product $product): array
    {
        $specifications = [];

        foreach ($product->attributeValues as $value) {
            $name = $value->attribute->name ?? null;

            // Variant-level values are the variant's, not the product's.
            if ($name === null || $value->product_variant_id !== null) {
                continue;
            }

            $specifications[$name] = $value->display();
        }

        return $specifications;
    }

    /**
     * The subset a customer may filter on, keyed by code.
     *
     * Only attributes a moderator marked filterable: §22 says filters come
     * from the category definitions rather than from a hardcoded list, and
     * this is where that decision is honoured. Values are arrays because a
     * multi-select attribute has more than one.
     *
     * @return array<string, array<int, string>>
     */
    private function filterableAttributes(Product $product): array
    {
        $filterable = [];

        foreach ($product->attributeValues as $value) {
            $attribute = $value->attribute;

            if ($attribute === null || $value->product_variant_id !== null || ! $attribute->is_filterable) {
                continue;
            }

            $filterable[$attribute->code] ??= [];
            $filterable[$attribute->code][] = $this->filterValue($value);
        }

        return array_map(
            static fn (array $values): array => array_values(array_unique($values)),
            $filterable,
        );
    }

    /** The value as a filter compares it: the option's code, or the raw scalar. */
    private function filterValue(ProductAttributeValue $value): string
    {
        return $value->option->value ?? $value->raw();
    }

    /** "Electronics > Mobile Phones > Smartphones", for keyword reach. */
    private function categoryPath(Product $product): string
    {
        $category = $product->category;

        if ($category === null) {
            return '';
        }

        $names = Category::query()
            ->whereIn('id', $category->ancestorIds() ?: [$category->id])
            ->orderBy('depth')
            ->pluck('name')
            ->all();

        return implode(' > ', $names);
    }
}
