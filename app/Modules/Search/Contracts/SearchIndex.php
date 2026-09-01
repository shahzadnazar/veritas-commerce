<?php

declare(strict_types=1);

namespace App\Modules\Search\Contracts;

use App\Modules\Search\Data\IndexableProduct;

/**
 * The search port.
 *
 * M3 owns marketplace search. What M2 owes it is a contract the catalogue
 * already speaks, so the engine can be replaced without touching a single
 * catalogue action. PostgreSQL backs it today; OpenSearch or Meilisearch
 * would be a second implementation and nothing else.
 *
 * Every method is idempotent by design: indexing runs on a queue, jobs are
 * retried, and reindexing a document that is already current must be a
 * no-op rather than a duplicate.
 */
interface SearchIndex
{
    /** Add or replace one product's document. */
    public function index(IndexableProduct $product): void;

    /** Remove a product, whether it was ever indexed or not. */
    public function forget(int $productId): void;

    /**
     * Products matching a phrase, most relevant first.
     *
     * @return array<int, int> product ids
     */
    public function search(string $phrase, int $limit = 24): array;
}
