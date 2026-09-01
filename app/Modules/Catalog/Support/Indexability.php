<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Support;

use App\Modules\Search\Data\SearchQuery;

/**
 * One place that decides whether a URL may be indexed.
 *
 * §39 asks for a central policy rather than scattered meta tags, and the
 * reason is arithmetic: five brands, four price bands, three sorts and ten
 * pages is six hundred URLs for one category, all showing subsets of the
 * same products. Left alone that is how a catalogue of two thousand
 * products becomes a million crawlable pages and none of them rank.
 *
 * The rule:
 *
 *   - A clean category page, page one, is the canonical thing and indexes.
 *   - Page two onward follows links but does not index; the canonical
 *     stays the base category so the authority stays in one place.
 *   - Any faceted combination is noindex, follow. A crawler is welcome to
 *     walk through to the products; the permutation itself is not a page
 *     worth ranking.
 *   - Search results never index. §38.
 *
 * "follow" throughout, deliberately: refusing to index a page is not a
 * reason to strand the products it links to.
 */
final class Indexability
{
    public const INDEX = 'index, follow';

    public const NOINDEX = 'noindex, follow';

    /**
     * A page that belongs to one person.
     *
     * A cart, a checkout, an order, a portal screen. "nofollow" as well as
     * "noindex" here, unlike a faceted listing: there is nothing on the
     * other side of these links a crawler should be walking toward, and
     * the pages themselves are meaningless — or unreachable — without the
     * session that owns them.
     */
    public const PRIVATE = 'noindex, nofollow';

    /**
     * The transactional and account paths, as route patterns.
     *
     * Listed once, here, rather than remembered at each controller: a
     * page that forgets its meta tag is a page a crawler indexes, and the
     * one that would hurt most is the one holding somebody's address.
     *
     * @return array<int, string>
     */
    public static function privatePaths(): array
    {
        return [
            'cart', 'cart/*',
            'checkout', 'checkout/*',
            'account', 'account/*',
        ];
    }

    /**
     * A category or store listing.
     *
     * @return array{robots: string, canonical: string}
     */
    public static function forListing(string $canonicalUrl, SearchQuery $query): array
    {
        $isCanonical = $query->page === 1 && ! $query->hasFilters() && ! $query->hasPhrase();

        return [
            'robots' => $isCanonical ? self::INDEX : self::NOINDEX,
            // Always the clean URL. A filtered page pointing at itself
            // would be asking for the permutation to be ranked.
            'canonical' => $canonicalUrl,
        ];
    }

    /**
     * Search results.
     *
     * Never indexable, whatever the query. A search URL is a record of
     * something one person typed once, and letting crawlers enumerate that
     * space produces infinite thin pages.
     *
     * @return array{robots: string, canonical: string}
     */
    public static function forSearch(string $canonicalUrl): array
    {
        return ['robots' => self::NOINDEX, 'canonical' => $canonicalUrl];
    }
}
