<?php

declare(strict_types=1);

namespace App\Modules\Search\Data;

/**
 * What a discovery query returned.
 *
 * Facets are part of the result rather than a second call, because they
 * have to be counted over the same filtered set — a brand facet computed
 * without the active price filter would offer the customer a choice that
 * leads nowhere.
 */
final readonly class SearchResults
{
    /**
     * @param  array<int, SearchHit>  $hits
     * @param  array<string, array<int, FacetValue>>  $facets  facet key => values
     */
    public function __construct(
        public array $hits,
        public int $total,
        public int $page,
        public int $perPage,
        public array $facets = [],
        /** A better spelling, when the query found nothing and one exists. */
        public ?string $suggestion = null,
    ) {}

    public static function empty(int $page = 1, int $perPage = 24): self
    {
        return new self(hits: [], total: 0, page: $page, perPage: $perPage);
    }

    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total / max(1, $this->perPage)));
    }

    public function isEmpty(): bool
    {
        return $this->hits === [];
    }
}
