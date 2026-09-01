<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Queries;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Offers\Queries\OfferEligibility;
use App\Modules\Search\Contracts\IndexableProductSource;
use App\Modules\Search\Data\IndexableProduct;

/**
 * Describes one product for the search index.
 *
 * Lives in the catalogue rather than in Search because describing a
 * product is the catalogue's job: an index that read these models itself
 * would become a second, quietly diverging definition of what a product
 * is. Search sees only the interface and the flat document.
 *
 * The price and offer count come from the same eligibility rule the
 * storefront uses, so a product cannot appear in search at a price nobody
 * can actually buy it at.
 */
final class BuildIndexableProduct implements IndexableProductSource
{
    public function __construct(private readonly OfferEligibility $eligibility) {}

    public function describe(int $productId): ?IndexableProduct
    {
        $product = Product::query()
            ->with(['brand', 'category', 'attributeValues.attribute', 'attributeValues.option'])
            ->find($productId);

        if ($product === null) {
            return null;
        }

        $offers = $this->eligibility->query()->where('product_id', $product->id)->get();

        $specifications = [];

        foreach ($product->attributeValues as $value) {
            $name = $value->attribute?->name;

            // Variant-level values are the variant's, not the product's.
            if ($name === null || $value->product_variant_id !== null) {
                continue;
            }

            $specifications[$name] = $value->display();
        }

        return new IndexableProduct(
            productId: $product->id,
            title: $product->title,
            description: $product->description,
            brandName: $product->brand?->name,
            categoryPath: $this->categoryPath($product),
            identifiers: array_values($product->identifiers()),
            specifications: $specifications,
            isPublic: $product->isPubliclyVisible(),
            lowestPriceMinor: $offers->isEmpty() ? null : (int) $offers->min('price_minor'),
            offerCount: $offers->count(),
        );
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
