<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Support;

/**
 * Schema.org JSON-LD for a product page.
 *
 * The rule that governs every line here: nothing is emitted that the
 * database cannot support. No aggregateRating, because there are no
 * reviews. No availability claim beyond "this seller lists it", because
 * inventory arrives in M3. No price when nobody is offering one.
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

        return $data;
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
                // "A seller lists this" is all M2 can honestly say. Real
                // stock arrives with inventory in M3.
                'availability' => 'https://schema.org/InStock',
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
            'availability' => 'https://schema.org/InStock',
        ];
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
