<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Queries\BuildDiscoveryPage;
use App\Modules\Catalog\Queries\SearchQueryFactory;
use App\Modules\Catalog\Support\Indexability;
use App\Modules\Events\Actions\RecordInteraction;
use App\Modules\Events\Enums\InteractionEventType;
use App\Modules\Search\Contracts\SearchIndex;
use App\Modules\Search\Data\Suggestion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Customer search.
 *
 * URL-driven throughout, so a result set can be linked, bookmarked and
 * shared — §21 rules out a client-only search whose state lives in React
 * and vanishes on reload.
 *
 * The page itself is never indexable. A search URL records what one person
 * typed once, and a crawler enumerating that space produces infinite thin
 * pages that compete with the category pages that should rank.
 */
final class SearchController
{
    public function __construct(
        private readonly SearchQueryFactory $queries,
        private readonly BuildDiscoveryPage $page,
        private readonly SearchIndex $index,
        private readonly RecordInteraction $interactions,
    ) {}

    public function __invoke(Request $request): Response
    {
        $query = ($this->queries)($request);
        $page = ($this->page)($query);

        $base = rtrim((string) config('veritas.identity.public_url'), '/');

        // Recorded after the results are known, so the event carries how
        // many there were — a zero-result search is the interesting one.
        $this->interactions->search(
            $request,
            phrase: $query->phrase,
            resultCount: $page['results']['total'],
            filters: $page['applied'],
        );

        return Inertia::render('Search/Index', [
            ...$page,
            'seo' => [
                'title' => $query->hasPhrase()
                    ? "Search: {$query->phrase}"
                    : 'Search',
                ...Indexability::forSearch($base.'/search'),
            ],
        ]);
    }

    /**
     * Autocomplete.
     *
     * Deliberately a JSON endpoint rather than an Inertia page: it runs on
     * keystrokes, returns a handful of rows, and must stay small. Rate
     * limited, minimum two characters, and it reads the same index the
     * results page does — so it can never suggest something unpublished.
     */
    public function suggestions(Request $request): JsonResponse
    {
        $prefix = trim($request->string('q')->toString());

        if (mb_strlen($prefix) < 2) {
            return response()->json(['suggestions' => []]);
        }

        $suggestions = $this->index->suggest(mb_substr($prefix, 0, 100), 8);

        return response()->json([
            'suggestions' => array_map(
                static fn (Suggestion $suggestion): array => [
                    'type' => $suggestion->type,
                    'label' => $suggestion->label,
                    'url' => $suggestion->url,
                    'context' => $suggestion->context,
                ],
                $suggestions,
            ),
        ]);
    }

    /** Records that a customer clicked a result, and where it sat. */
    public function click(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product' => ['required', 'string', 'max:64'],
            'position' => ['required', 'integer', 'min:1', 'max:1000'],
            'query' => ['nullable', 'string', 'max:200'],
        ]);

        $this->interactions->record(
            $request,
            InteractionEventType::SearchResultClicked,
            subjectType: 'product',
            subjectPublicId: (string) $validated['product'],
            payload: [
                'position' => (int) $validated['position'],
                'query' => (string) ($validated['query'] ?? ''),
            ],
        );

        return response()->json(['recorded' => true]);
    }
}
