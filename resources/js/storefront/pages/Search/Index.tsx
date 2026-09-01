import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { StorefrontLayout } from '../../../design-system/layout/StorefrontLayout';
import { Button } from '../../../design-system/primitives/Button';
import { Field, Input } from '../../../design-system/primitives/Field';
import { EmptyState } from '../../../design-system/patterns/States';
import { ProductGrid } from '../../../design-system/patterns/ProductCard';
import type { ProductCardData } from '../../../design-system/patterns/ProductCard';
import { DiscoveryFilters, Pagination, SortSelect } from '../../components/DiscoveryFilters';
import type { AppliedFilters, Facets } from '../../components/DiscoveryFilters';
import type { SharedPageProps } from '../../../shared/types';

interface SearchProps extends SharedPageProps {
    results: {
        data: ProductCardData[];
        total: number;
        page: number;
        lastPage: number;
        perPage: number;
    };
    facets: Facets;
    applied: AppliedFilters;
    sorts: { value: string; label: string }[];
    suggestion: string | null;
    seo: { title: string; canonical: string; robots: string };
}

/**
 * Customer search.
 *
 * The URL is the state. Everything — the query, the filters, the sort, the
 * page — round-trips through it, so a result set can be shared and
 * reloaded, and the server does the searching. It is deliberately
 * noindex: a search URL records what one person typed once.
 */
export default function Index() {
    const { results, facets, applied, sorts, suggestion, seo } = usePage<SearchProps>().props;
    const [query, setQuery] = useState(applied.q);

    const recordClick = (product: ProductCardData, position: number) => {
        // Fire-and-forget: the click has to be recorded with its position
        // for ranking work later, and must never delay the navigation.
        void fetch('/search/click', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN':
                    document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ??
                    '',
            },
            body: JSON.stringify({ product: product.slug, position, query: applied.q }),
            keepalive: true,
        }).catch(() => {
            // Analytics must never break browsing.
        });
    };

    return (
        <StorefrontLayout title={seo.title}>
            <Head>
                <meta name="robots" content={seo.robots} />
                <link rel="canonical" href={seo.canonical} />
            </Head>

            <form
                className="mb-8 flex max-w-[560px] items-end gap-3"
                onSubmit={(event) => {
                    event.preventDefault();
                    router.get('/search', { q: query } as never);
                }}
            >
                <div className="flex-1">
                    <Field label="Search the catalogue">
                        {({ id }) => (
                            <Input
                                id={id}
                                type="search"
                                value={query}
                                onChange={(event) => setQuery(event.target.value)}
                            />
                        )}
                    </Field>
                </div>
                <Button type="submit" variant="primary">
                    Search
                </Button>
            </form>

            {applied.q !== '' ? (
                <p className="mb-6 text-[15px]">
                    <span className="vc-tabular">{results.total}</span>{' '}
                    {results.total === 1 ? 'result' : 'results'} for{' '}
                    <span className="font-semibold">“{applied.q}”</span>
                </p>
            ) : null}

            {results.total === 0 ? (
                <div className="max-w-[62ch]">
                    <EmptyState
                        title={applied.q === '' ? 'Search the catalogue' : 'Nothing matched'}
                        body={
                            applied.q === ''
                                ? 'Type what you are looking for. You can also browse by category.'
                                : 'No products matched that search.'
                        }
                    />

                    {suggestion ? (
                        <p className="mt-4 text-[15px]">
                            Did you mean{' '}
                            <Link
                                href={`/search?q=${encodeURIComponent(suggestion)}`}
                                className="font-semibold underline underline-offset-4"
                            >
                                {suggestion}
                            </Link>
                            ?
                        </p>
                    ) : null}

                    {applied.hasFilters ? (
                        <p className="mt-4 text-[15px]">
                            Your filters may be the reason.{' '}
                            <Link
                                href={`/search?q=${encodeURIComponent(applied.q)}`}
                                className="font-semibold underline underline-offset-4"
                            >
                                Search without them
                            </Link>
                            .
                        </p>
                    ) : null}
                </div>
            ) : (
                <div className="grid gap-10 lg:grid-cols-[240px_minmax(0,1fr)]">
                    <DiscoveryFilters url="/search" facets={facets} applied={applied} />

                    <div>
                        <div className="mb-6 flex justify-end">
                            <div className="min-w-[200px]">
                                <SortSelect url="/search" applied={applied} sorts={sorts} />
                            </div>
                        </div>

                        <ProductGrid products={results.data} onSelect={recordClick} />

                        <Pagination
                            url="/search"
                            applied={applied}
                            page={results.page}
                            lastPage={results.lastPage}
                        />
                    </div>
                </div>
            )}
        </StorefrontLayout>
    );
}
