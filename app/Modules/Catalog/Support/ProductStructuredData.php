<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Support;

use App\Modules\Inventory\Enums\StockState;

/**
 * Schema.org JSON-LD for a product page.
 *
 * The rule that governs every line here: nothing is emitted that the
 * database cannot support. No availability claim beyond what the inventory
 * ledger says. No price when nobody is offering one. And — the M8 addition
 * — no aggregateRating until real customers have left real reviews.
 *
 * That last one is worth being explicit about. `aggregateRating` is
 * emitted only when the rating summary reports at least one PUBLISHED
 * review, and the values come from the same summary the visible page
 * reads. A product nobody has reviewed emits nothing at all rather than a
 * zero, and a review a moderator hides stops contributing to both the page
 * and the markup in the same transaction.
 *
 * Structured data is a statement to a search engine on the marketplace's
 * behalf. A rich result showing four stars for a product nobody has
 * reviewed, or a price that does not exist, is a lie that costs the
 * domain's standing rather than one page's ranking.
 */
final class ProductStructuredData
{
    /**
     * @param  array<string, mixed>  $page  the shape BuildProductPage returns
     * @return array<string, mixed>
     */
    public static function product(array $page, string $canonical): array
    {
        /** @var array<string, mixed> $product */
        $product = $page['product'];
        /** @var array<int, array<string, mixed>> $media */
        $media = $page['media'];
        /** @var array<string, string> $identifiers */
        $identifiers = $product['identifiers'];

        $data = array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $product['title'],
            'description' => $product['description'],
            'url' => $canonical,
            'sku' => $identifiers['mpn'] ?? null,
            'gtin13' => $identifiers['ean'] ?? null,
            'gtin12' => $identifiers['upc'] ?? null,
            'gtin14' => $identifiers['gtin'] ?? null,
            'isbn' => $identifiers['isbn'] ?? null,
            'mpn' => $identifiers['mpn'] ?? null,
            'image' => array_map(
                static fn (array $image): string => (string) $image['url'],
                $media,
            ),
            'brand' => $product['brand'] === null ? null : [
                '@type' => 'Brand',
                'name' => $product['brand']['name'],
            ],
            'category' => $product['category']['name'] ?? null,
            'additionalProperty' => self::specifications($page),
        ], static fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '');

        $offers = self::offers($page, $canonical);

        if ($offers !== null) {
            $data['offers'] = $offers;
        }

        $rating = self::aggregateRating($page);

        if ($rating !== null) {
            $data['aggregateRating'] = $rating;
        }

        return $data;
    }

    /**
     * The rating, when there is one. §67 and §69.
     *
     * Reads the same `rating` block the visible page renders, so the two
     * cannot drift: a product page saying 4.6 while its markup claims 4.8
     * is the failure §16 exists to prevent, and it is prevented by there
     * being one number rather than by two computations agreeing.
     *
     * `ratingValue` is emitted at the precision it was computed to, and
     * `reviewCount` counts published reviews only — a hidden review is not
     * in the summary, so it is not in the markup either.
     *
     * @param  array<string, mixed>  $page
     * @return array<string, mixed>|null
     */
    private static function aggregateRating(array $page): ?array
    {
        /** @var array<string, mixed>|null $rating */
        $rating = $page['rating'] ?? null;

        if ($rating === null || ($rating['hasRating'] ?? false) !== true) {
            return null;
        }

        $count = (int) ($rating['reviewCount'] ?? 0);
        $average = $rating['average'] ?? null;

        // Belt and braces: a summary claiming an average with no reviews
        // behind it would be a corrupt row, and it must not become a
        // structured-data claim on the marketplace's behalf.
        if ($count < 1 || ! is_numeric($average)) {
            return null;
        }

        return [
            '@type' => 'AggregateRating',
            'ratingValue' => number_format((float) $average, 2, '.', ''),
            'reviewCount' => $count,
            'bestRating' => 5,
            'worstRating' => 1,
        ];
    }

    /**
     * @param  array<int, array{name: string, url: string}>  $crumbs
     * @return array<string, mixed>
     */
    public static function breadcrumbs(array $crumbs, string $base): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => array_map(
                static fn (int $index, array $crumb): array => [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'name' => $crumb['name'],
                    'item' => rtrim($base, '/').$crumb['url'],
                ],
                array_keys($crumbs),
                $crumbs,
            ),
        ];
    }

    /**
     * An Offer for a single seller, an AggregateOffer for several, and
     * nothing at all when nobody is selling it.
     *
     * @param  array<string, mixed>  $page
     * @return array<string, mixed>|null
     */
    private static function offers(array $page, string $canonical): ?array
    {
        /** @var array<int, array<string, mixed>> $offers */
        $offers = $page['offers'];

        if ($offers === []) {
            return null;
        }

        /** @var array{fromMinor: int, toMinor: int, currency: string, isSingle: bool}|null $range */
        $range = $page['priceRange'];

        if ($range === null) {
            return null;
        }

        // Prices are held in minor units; schema.org wants a decimal
        // string, and building it by division would reintroduce exactly
        // the floating point the ledger avoids.
        $low = self::decimal($range['fromMinor']);
        $high = self::decimal($range['toMinor']);

        if (count($offers) === 1 || $range['isSingle']) {
            return array_filter([
                '@type' => 'Offer',
                'url' => $canonical,
                'price' => $low,
                'priceCurrency' => $range['currency'],
                // The inventory ledger's answer, not an assumption. A
                // product whose sellers have all run out says OutOfStock,
                // because a page that claims InStock and then cannot
                // fulfil is how a marketplace earns a manual penalty.
                'availability' => self::availability($page),
                'itemCondition' => self::condition((string) $offers[0]['condition']),
            ], static fn (mixed $value): bool => $value !== null);
        }

        return [
            '@type' => 'AggregateOffer',
            'url' => $canonical,
            'lowPrice' => $low,
            'highPrice' => $high,
            'priceCurrency' => $range['currency'],
            'offerCount' => count($offers),
            'availability' => self::availability($page),
        ];
    }

    /**
     * schema.org availability, from real stock.
     *
     * @param  array<string, mixed>  $page
     */
    private static function availability(array $page): string
    {
        return ($page['inStock'] ?? false) === true
            ? StockState::InStock->schemaAvailability()
            : StockState::OutOfStock->schemaAvailability();
    }

    private static function decimal(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
    }

    private static function condition(string $condition): ?string
    {
        return match ($condition) {
            'new' => 'https://schema.org/NewCondition',
            'refurbished' => 'https://schema.org/RefurbishedCondition',
            'used_like_new', 'used_good', 'used_acceptable' => 'https://schema.org/UsedCondition',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $page
     * @return array<int, array<string, string>>
     */
    private static function specifications(array $page): array
    {
        /** @var array<int, array{name: string, value: string}> $specifications */
        $specifications = $page['specifications'];

        return array_map(
            static fn (array $specification): array => [
                '@type' => 'PropertyValue',
                'name' => $specification['name'],
                'value' => $specification['value'],
            ],
            $specifications,
        );
    }
}
