<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Queries\SitemapUrls;
use Illuminate\Http\Response;

/**
 * Sitemaps, as an index plus one file per kind.
 *
 * A single flat sitemap would work at today's size and would have to be
 * rebuilt as three the first time the catalogue outgrows fifty thousand
 * URLs. Splitting now costs one extra route and means growth is a
 * pagination change rather than a redesign — and it lets a crawler fetch
 * only the part that changed.
 *
 * Only publicly eligible URLs go in. A sitemap that lists a suspended
 * product is telling a crawler to fetch a 404, which is the fastest way to
 * teach it to trust the file less.
 *
 * Cached briefly rather than generated per request: crawlers fetch these
 * repeatedly, and the answer changes on the timescale of moderation
 * decisions, not of requests.
 */
final class SitemapController
{
    private const CACHE_SECONDS = 900;

    public function __construct(private readonly SitemapUrls $urls) {}

    public function index(): Response
    {
        $base = $this->base();

        $sitemaps = array_map(
            static fn (string $name): string => sprintf(
                '<sitemap><loc>%s/%s</loc></sitemap>',
                $base,
                $name,
            ),
            ['products-sitemap.xml', 'categories-sitemap.xml', 'stores-sitemap.xml'],
        );

        return $this->xml(
            '<?xml version="1.0" encoding="UTF-8"?>'.
            '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.
            implode('', $sitemaps).
            '</sitemapindex>'
        );
    }

    public function products(): Response
    {
        return $this->urlSet($this->urls->products());
    }

    public function categories(): Response
    {
        return $this->urlSet($this->urls->categories());
    }

    public function stores(): Response
    {
        return $this->urlSet($this->urls->stores());
    }

    /**
     * robots.txt, generated so the sitemap location cannot drift from the
     * routes that serve it.
     */
    public function robots(): Response
    {
        $base = $this->base();

        $lines = [
            'User-agent: *',
            // The portals are not for crawlers, and neither is the search
            // space — §38 again, stated where a crawler reads it first.
            'Disallow: /admin',
            'Disallow: /seller',
            'Disallow: /search',
            'Allow: /',
            '',
            "Sitemap: {$base}/sitemap.xml",
        ];

        return response(implode("\n", $lines)."\n", 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age='.self::CACHE_SECONDS,
        ]);
    }

    /** @param  array<int, array{loc: string, lastmod: string|null}>  $urls */
    private function urlSet(array $urls): Response
    {
        $entries = array_map(
            static fn (array $url): string => sprintf(
                '<url><loc>%s</loc>%s</url>',
                htmlspecialchars($url['loc'], ENT_XML1),
                $url['lastmod'] === null ? '' : '<lastmod>'.$url['lastmod'].'</lastmod>',
            ),
            $urls,
        );

        return $this->xml(
            '<?xml version="1.0" encoding="UTF-8"?>'.
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.
            implode('', $entries).
            '</urlset>'
        );
    }

    private function xml(string $body): Response
    {
        return response($body, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age='.self::CACHE_SECONDS,
        ]);
    }

    private function base(): string
    {
        return rtrim((string) config('veritas.identity.public_url'), '/');
    }
}
