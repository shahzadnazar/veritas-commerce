<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Queries;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductMedia;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Media\Contracts\ObjectStore;
use App\Modules\Media\Enums\Visibility;
use App\Modules\Offers\Models\Offer;
use App\Modules\Offers\Queries\OfferEligibility;
use App\Modules\Offers\Queries\OfferRankingService;
use App\Support\Money;

/**
 * Everything the public product page shows, in one read.
 *
 * Assembled here rather than in the controller so the same shape can serve
 * the page, its structured data and — in M3 — a comparison view, without
 * three surfaces each running their own slightly different queries.
 *
 * Every relation is eager-loaded deliberately: a product with six
 * variants, twelve specifications, five images and four sellers' offers is
 * the normal case, and doing it lazily is how a page turns into ninety
 * queries.
 */
final class BuildProductPage
{
    public function __construct(
        private readonly OfferEligibility $eligibility,
        private readonly OfferRankingService $ranking,
        private readonly ObjectStore $objects,
    ) {}

    /** @return array<string, mixed> */
    public function __invoke(Product $product): array
    {
        $offers = $this->eligibility->query()
            ->with(['store', 'sellerAccount', 'productVariant'])
            ->where('product_id', $product->id)
            ->get();

        $ranked = $this->ranking->rank($offers);
        $featured = $ranked->first();
        $range = $this->ranking->priceRange($offers);
        $currency = $featured->currency ?? (string) config('veritas.money.default_currency');

        return [
            'product' => [
                'publicId' => $product->public_id,
                'title' => $product->title,
                'slug' => $product->slug,
                'description' => $product->description,
                'brand' => $product->brand === null ? null : [
                    'name' => $product->brand->name,
                    'slug' => $product->brand->slug,
                ],
                'category' => $product->category === null ? null : [
                    'name' => $product->category->name,
                    'slug' => $product->category->slug,
                ],
                'identifiers' => $product->identifiers(),
            ],
            'breadcrumbs' => $this->breadcrumbs($product),
            'media' => $product->media
                // Only images a worker has finished with: a half-processed
                // upload should not appear as a broken frame.
                ->filter(fn (ProductMedia $media): bool => $media->isReady())
                ->map(fn (ProductMedia $media): array => [
                    'url' => $this->objects->url(
                        $this->objects->fromReference($media->reference(), Visibility::Public),
                    ),
                    'alt' => $media->alt_text ?? $product->title,
                    'isPrimary' => $media->is_primary,
                    'width' => $media->width,
                    'height' => $media->height,
                ])
                ->values()
                ->all(),
            'specifications' => $this->specifications($product),
            'variants' => $product->variants
                ->map(fn (ProductVariant $variant): array => [
                    'publicId' => $variant->public_id,
                    'name' => $variant->name,
                    'options' => $variant->option_values ?? [],
                    // A variant nobody offers is shown as unavailable
                    // rather than hidden, so the range on offer is honest.
                    'hasOffer' => $offers->contains(
                        fn (Offer $offer): bool => $offer->product_variant_id === $variant->id,
                    ),
                ])
                ->values()
                ->all(),
            'offers' => $ranked
                ->map(fn (Offer $offer): array => [
                    'publicId' => $offer->public_id,
                    'price' => Money::of($offer->price_minor, $offer->currency)->format(),
                    'priceMinor' => $offer->price_minor,
                    'compareAtPrice' => $offer->compare_at_price_minor === null
                        ? null
                        : Money::of($offer->compare_at_price_minor, $offer->currency)->format(),
                    'currency' => $offer->currency,
                    'condition' => $offer->condition->value,
                    'conditionLabel' => $offer->condition->label(),
                    'handlingDays' => $offer->handling_days,
                    'variantPublicId' => $offer->productVariant?->public_id,
                    'seller' => [
                        'storeName' => $offer->store->name ?? 'A Veritas seller',
                        'storeSlug' => $offer->store?->slug,
                    ],
                ])
                ->values()
                ->all(),
            'featuredOfferPublicId' => $featured?->public_id,
            'priceRange' => $range === null ? null : [
                'from' => Money::of($range['from'], $currency)->format(),
                'to' => Money::of($range['to'], $currency)->format(),
                'fromMinor' => $range['from'],
                'toMinor' => $range['to'],
                'currency' => $currency,
                'isSingle' => $range['from'] === $range['to'],
            ],
            'offerCount' => $offers->count(),
        ];
    }

    /** @return array<int, array{name: string, url: string}> */
    private function breadcrumbs(Product $product): array
    {
        $category = $product->category;
        $crumbs = [];

        if ($category !== null) {
            $ancestors = Category::query()
                ->whereIn('id', $category->ancestorIds() ?: [$category->id])
                ->orderBy('depth')
                ->get();

            foreach ($ancestors as $ancestor) {
                $crumbs[] = ['name' => $ancestor->name, 'url' => '/categories/'.$ancestor->slug];
            }
        }

        $crumbs[] = ['name' => $product->title, 'url' => '/products/'.$product->slug];

        return $crumbs;
    }

    /** @return array<int, array{name: string, value: string}> */
    private function specifications(Product $product): array
    {
        $specifications = [];

        foreach ($product->attributeValues as $value) {
            // Variant-level values belong to the variant picker, not to
            // the product's own specification table.
            if ($value->product_variant_id !== null || $value->attribute === null) {
                continue;
            }

            $specifications[] = ['name' => $value->attribute->name, 'value' => $value->display()];
        }

        return $specifications;
    }
}
