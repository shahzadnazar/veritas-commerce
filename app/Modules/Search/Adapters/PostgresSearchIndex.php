<?php

declare(strict_types=1);

namespace App\Modules\Search\Adapters;

use App\Modules\Search\Contracts\SearchIndex;
use App\Modules\Search\Data\IndexableProduct;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Search over PostgreSQL's own full-text index.
 *
 * Adequate for the catalogue M2 builds and free of new infrastructure. The
 * interface is what matters: when M3 needs typo tolerance, synonyms and
 * faceting at scale, a second implementation is written and this one is
 * unbound. No catalogue action changes.
 */
final class PostgresSearchIndex implements SearchIndex
{
    public function index(IndexableProduct $product): void
    {
        // Upsert, because indexing runs on a retryable queue: the same job
        // delivered twice must leave one document, not two.
        DB::table('product_search_documents')->upsert(
            [[
                'product_id' => $product->productId,
                'title' => $product->title,
                'brand_name' => $product->brandName,
                'category_path' => $product->categoryPath,
                'searchable_text' => $product->searchableText(),
                'is_public' => $product->isPublic,
                'lowest_price_minor' => $product->lowestPriceMinor,
                'offer_count' => $product->offerCount,
                'indexed_at' => Carbon::now(),
            ]],
            ['product_id'],
            ['title', 'brand_name', 'category_path', 'searchable_text', 'is_public', 'lowest_price_minor', 'offer_count', 'indexed_at'],
        );
    }

    public function forget(int $productId): void
    {
        DB::table('product_search_documents')->where('product_id', $productId)->delete();
    }

    /** @return array<int, int> */
    public function search(string $phrase, int $limit = 24): array
    {
        $phrase = trim($phrase);

        if ($phrase === '') {
            return [];
        }

        /** @var array<int, stdClass> $rows */
        $rows = DB::select(
            "select product_id
             from product_search_documents
             where is_public and search_vector @@ websearch_to_tsquery('english', ?)
             order by ts_rank(search_vector, websearch_to_tsquery('english', ?)) desc, product_id
             limit ?",
            [$phrase, $phrase, $limit],
        );

        return array_map(static fn (object $row): int => (int) $row->product_id, $rows);
    }
}
