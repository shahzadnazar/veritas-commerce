import { Head, usePage } from '@inertiajs/react';
import { StorefrontLayout } from '../../../design-system/layout/StorefrontLayout';
import { EmptyState } from '../../../design-system/patterns/States';
import { ProductGrid } from '../../../design-system/patterns/ProductCard';
import type { ProductCardData } from '../../../design-system/patterns/ProductCard';
import { Pagination, SortSelect } from '../../components/DiscoveryFilters';
import type { AppliedFilters, Facets } from '../../components/DiscoveryFilters';
import type { SharedPageProps } from '../../../shared/types';

interface StoreShowProps extends SharedPageProps {
    store: {
        name: string;
        slug: string;
        description: string | null;
        supportEmail: string | null;
        shippingPolicy: string | null;
        returnPolicy: string | null;
        isOpen: boolean;
        shipsFrom: string;
    };
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
    seo: {
        title: string;
        description: string;
        canonical: string;
        ogTitle: string;
        ogType: string;
        ogUrl: string;
        robots: string;
    };
}

/**
 * The public store page.
 *
 * The grid is the same discovery engine, cards and sorting as search and
 * category pages, scoped to this seller — so a product cannot appear here
 * on different terms from the rest of the site. Another seller's offer has
 * no path into this listing: the scope is applied in the query, not
 * filtered afterwards.
 */
export default function Show() {
    const { store, results, applied, sorts, seo } = usePage<StoreShowProps>().props;

    const url = `/stores/${store.slug}`;

    return (
        <StorefrontLayout title={seo.title}>
            <Head>
                <meta name="description" content={seo.description} />
                <meta name="robots" content={seo.robots} />
                <link rel="canonical" href={seo.canonical} />
                <meta property="og:title" content={seo.ogTitle} />
                <meta property="og:description" content={seo.description} />
                <meta property="og:type" content={seo.ogType} />
                <meta property="og:url" content={seo.ogUrl} />
            </Head>

            <header className="mb-10 border-b-2 border-[var(--vc-text)] pb-8">
                <h1 className="mb-2 text-[38px]">{store.name}</h1>
                <p className="text-[13px] text-[var(--vc-neutral-600)]">
                    /stores/{store.slug}
                    {store.shipsFrom ? ` · ships from ${store.shipsFrom}` : ''}
                </p>

                {store.description ? (
                    <p className="mt-5 max-w-[62ch] text-[var(--vc-neutral-700)]">
                        {store.description}
                    </p>
                ) : null}

                {!store.isOpen ? (
                    <p className="mt-5 border-2 border-[var(--vc-divider)] px-4 py-3 text-[14px]">
                        This store is not taking orders at the moment.
                    </p>
                ) : null}
            </header>

            <div className="grid gap-10 md:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                <section>
                    <div className="mb-5 flex flex-wrap items-end justify-between gap-4">
                        <h2 className="text-[24px]">
                            Products
                            {results.total > 0 ? (
                                <span className="vc-tabular ml-2 text-[15px] text-[var(--vc-neutral-600)]">
                                    {results.total}
                                </span>
                            ) : null}
                        </h2>
                        {results.total > 1 ? (
                            <div className="min-w-[200px]">
                                <SortSelect url={url} applied={applied} sorts={sorts} />
                            </div>
                        ) : null}
                    </div>

                    {results.data.length === 0 ? (
                        <EmptyState
                            title="No products listed yet"
                            body="This seller has not published anything to the marketplace catalogue. Their listings will appear here once they do."
                        />
                    ) : (
                        <ProductGrid products={results.data} />
                    )}

                    <Pagination
                        url={url}
                        applied={applied}
                        page={results.page}
                        lastPage={results.lastPage}
                    />
                </section>

                <aside className="flex flex-col gap-6 text-[14px]">
                    {store.shippingPolicy ? (
                        <div>
                            <h2 className="mb-2 text-[16px]">Shipping</h2>
                            <p className="text-[var(--vc-neutral-700)]">{store.shippingPolicy}</p>
                        </div>
                    ) : null}

                    {store.returnPolicy ? (
                        <div>
                            <h2 className="mb-2 text-[16px]">Returns</h2>
                            <p className="text-[var(--vc-neutral-700)]">{store.returnPolicy}</p>
                        </div>
                    ) : null}

                    {store.supportEmail ? (
                        <div>
                            <h2 className="mb-2 text-[16px]">Contact</h2>
                            <p className="text-[var(--vc-neutral-700)]">{store.supportEmail}</p>
                        </div>
                    ) : null}
                </aside>
            </div>
        </StorefrontLayout>
    );
}
