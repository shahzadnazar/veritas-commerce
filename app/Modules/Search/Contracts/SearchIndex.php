<?php

declare(strict_types=1);

namespace App\Modules\Search\Contracts;

use App\Modules\Search\Data\IndexableProduct;
use App\Modules\Search\Data\SearchQuery;
use App\Modules\Search\Data\SearchResults;
use App\Modules\Search\Data\Suggestion;

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
     * Kept alongside `query()` because plenty of callers — a reindex
     * assertion, an internal lookup — want ids and nothing else, and
     * making them build a SearchQuery to get them would be ceremony.
     *
     * @return array<int, int> product ids
     */
    public function search(string $phrase, int $limit = 24): array;

    /**
     * A full discovery query: filters, sorting, paging and facet counts.
     *
     * Everything customer-facing goes through here. The SearchQuery it
     * takes is already validated against the real catalogue, so an adapter
     * never has to defend itself against a hostile URL — that happens once,
     * in SearchQueryFactory, rather than in every engine that is ever
     * written.
     */
    public function query(SearchQuery $query): SearchResults;

    /**
     * Short, cheap completions for a partially typed query.
     *
     * Separate from `query()` because the constraints are different: it
     * runs on every keystroke, returns a handful of rows, and must never
     * reach anything unpublished.
     *
     * @return array<int, Suggestion>
     */
    public function suggest(string $prefix, int $limit = 8): array;
}
