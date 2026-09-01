import { Head, Link, usePage } from '@inertiajs/react';
import { StorefrontLayout } from '../../../design-system/layout/StorefrontLayout';
import { EmptyState } from '../../../design-system/patterns/States';
import { ProductGrid } from '../../../design-system/patterns/ProductCard';
import type { ProductCardData } from '../../../design-system/patterns/ProductCard';
import { StructuredData } from '../../../design-system/patterns/StructuredData';
import { DiscoveryFilters, Pagination, SortSelect } from '../../components/DiscoveryFilters';
import type { AppliedFilters, Facets } from '../../components/DiscoveryFilters';
import type { SharedPageProps } from '../../../shared/types';

interface CategoryShowProps extends SharedPageProps {
    category: { name: string; slug: string; description: string | null };
    breadcrumbs: { name: string; url: string }[];
    children: { name: string; slug: string }[];
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
    seo: { title: string; description: string | null; canonical: string; robots: string };
}

/**
 * A category as a real discovery page.
 *
 * Filters and sorting are URL state, so every view is linkable — but only
 * the clean first page is indexable. Six hundred crawlable permutations of
 * one category is how a catalogue disappears from search results, which is
 * why the robots directive comes from the server rather than from here.
 */
export default function Show() {
    const { category, breadcrumbs, children, results, facets, applied, sorts, seo } =
        usePage<CategoryShowProps>().props;

    const url = `/categories/${category.slug}`;

    return (
        <StorefrontLayout title={seo.title}>
            <Head>
                {seo.description ? <meta name="description" content={seo.description} /> : null}
                <meta name="robots" content={seo.robots} />
                <link rel="canonical" href={seo.canonical} />
            </Head>

            <StructuredData
                documents={[
                    {
                        '@context': 'https://schema.org',
                        '@type': 'BreadcrumbList',
                        itemListElement: breadcrumbs.map((crumb, index) => ({
                            '@type': 'ListItem',
                            position: index + 1,
                            name: crumb.name,
                            item: crumb.url,
                        })),
                    },
                ]}
            />

            <nav aria-label="Breadcrumb" className="mb-6 text-[13px] text-[var(--vc-neutral-600)]">
                <ol className="flex flex-wrap items-center gap-2">
                    {breadcrumbs.map((crumb, index) => (
                        <li key={crumb.url} className="flex items-center gap-2">
                            {index > 0 ? <span aria-hidden="true">/</span> : null}
                            <Link href={crumb.url} className="underline underline-offset-4">
                                {crumb.name}
                            </Link>
                        </li>
                    ))}
                </ol>
            </nav>

            <header className="mb-8">
                <h1 className="text-[32px] leading-tight">{category.name}</h1>
                {category.description ? (
                    <p className="mt-2 max-w-[68ch] text-[15px] text-[var(--vc-neutral-700)]">
                        {category.description}
                    </p>
                ) : null}
            </header>

            {children.length > 0 ? (
                <nav aria-label="Subcategories" className="mb-10 flex flex-wrap gap-2">
                    {children.map((child) => (
                        <Link
                            key={child.slug}
                            href={`/categories/${child.slug}`}
                            className="border-2 border-[var(--vc-text)] px-3 py-2 text-[14px] hover:bg-[var(--vc-surface)]"
                        >
                            {child.name}
                        </Link>
                    ))}
                </nav>
            ) : null}

            <div className="grid gap-10 lg:grid-cols-[240px_minmax(0,1fr)]">
                <DiscoveryFilters url={url} facets={facets} applied={applied} />

                <div>
                    <div className="mb-6 flex flex-wrap items-end justify-between gap-4">
                        <p className="vc-tabular text-[13px] text-[var(--vc-neutral-600)]">
                            {results.total} {results.total === 1 ? 'product' : 'products'}
                        </p>
                        <div className="min-w-[200px]">
                            <SortSelect url={url} applied={applied} sorts={sorts} />
                        </div>
                    </div>

                    {results.data.length === 0 ? (
                        <EmptyState
                            title="Nothing here yet"
                            body={
                                applied.hasFilters
                                    ? 'No products match these filters. Removing one may help.'
                                    : 'No products are listed in this category at the moment.'
                            }
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
                </div>
            </div>
        </StorefrontLayout>
    );
}
