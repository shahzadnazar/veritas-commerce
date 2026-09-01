<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Queries;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The public URLs worth crawling.
 *
 * Read from the search index for products, because the index already
 * encodes exactly one thing: whether the public may see it. Deriving the
 * sitemap from a second, hand-written definition of "eligible" is how a
 * sitemap ends up listing products the storefront 404s.
 *
 * Cached for a few minutes. A crawler fetches these on a schedule of its
 * own choosing and often; regenerating on every fetch would let a crawl
 * become a load test.
 */
final class SitemapUrls
{
    private const CACHE_SECONDS = 600;

    /** @return array<int, array{loc: string, lastmod: string|null}> */
    public function products(): array
    {
        return $this->remember('products', function (): array {
            /** @var array<int, stdClass> $rows */
            $rows = DB::table('product_search_documents')
                ->select('slug', 'published_at')
                // is_public is the index's own record of publish
                // eligibility, so a suspended or merged product is absent
                // here for the same reason it is absent from search.
                ->where('is_public', true)
                ->whereNotNull('slug')
                ->orderBy('product_id')
                ->limit(50_000)
                ->get()
                ->all();

            return array_map(
                fn (stdClass $row): array => [
                    'loc' => $this->base().'/products/'.$row->slug,
                    'lastmod' => $row->published_at === null
                        ? null
                        : substr((string) $row->published_at, 0, 10),
                ],
                $rows,
            );
        });
    }

    /** @return array<int, array{loc: string, lastmod: string|null}> */
    public function categories(): array
    {
        return $this->remember('categories', function (): array {
            /** @var array<int, stdClass> $rows */
            $rows = DB::table('categories')
                ->select('slug', 'updated_at')
                ->where('is_visible', true)
                ->orderBy('path')
                ->get()
                ->all();

            return array_map(
                fn (stdClass $row): array => [
                    'loc' => $this->base().'/categories/'.$row->slug,
                    'lastmod' => $row->updated_at === null ? null : substr((string) $row->updated_at, 0, 10),
                ],
                $rows,
            );
        });
    }

    /** @return array<int, array{loc: string, lastmod: string|null}> */
    public function stores(): array
    {
        return $this->remember('stores', function (): array {
            /** @var array<int, stdClass> $rows */
            $rows = DB::table('stores')
                ->join('seller_accounts', 'seller_accounts.id', '=', 'stores.seller_account_id')
                ->select('stores.slug', 'stores.updated_at')
                // Both halves matter: a closed store and a suspended
                // seller are different conditions with the same
                // consequence for a crawler.
                ->where('stores.is_open', true)
                ->where('seller_accounts.status', 'approved')
                ->orderBy('stores.id')
                ->get()
                ->all();

            return array_map(
                fn (stdClass $row): array => [
                    'loc' => $this->base().'/stores/'.$row->slug,
                    'lastmod' => $row->updated_at === null ? null : substr((string) $row->updated_at, 0, 10),
                ],
                $rows,
            );
        });
    }

    /**
     * @param  Closure(): array<int, array{loc: string, lastmod: string|null}>  $build
     * @return array<int, array{loc: string, lastmod: string|null}>
     */
    private function remember(string $key, Closure $build): array
    {
        return Cache::remember('sitemap:'.$key, self::CACHE_SECONDS, $build);
    }

    private function base(): string
    {
        return rtrim((string) config('veritas.identity.public_url'), '/');
    }
}
