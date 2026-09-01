import { Head, Link, usePage } from '@inertiajs/react';
import { StorefrontLayout } from '../../../design-system/layout/StorefrontLayout';
import { EmptyState } from '../../../design-system/patterns/States';
import type { SharedPageProps } from '../../../shared/types';

interface CategoryShowProps extends SharedPageProps {
    category: { name: string; slug: string; description: string | null };
    breadcrumbs: { name: string; url: string }[];
    children: { name: string; slug: string }[];
    products: {
        data: { title: string; slug: string; brand: string | null }[];
        currentPage: number;
        lastPage: number;
        total: number;
    };
    seo: { title: string; description: string; canonical: string; robots: string };
}

/**
 * A category page.
 *
 * Deliberately thin: filtering and facets belong to M3, and every facet
 * combination that becomes a crawlable URL is another near-duplicate
 * competing with the canonical product pages this links to.
 */
export default function Show() {
    const { category, breadcrumbs, children, products, seo } = usePage<CategoryShowProps>().props;

    return (
        <StorefrontLayout title={seo.title}>
            <Head>
                <meta name="description" content={seo.description} />
                <meta name="robots" content={seo.robots} />
                <link rel="canonical" href={seo.canonical} />
            </Head>

            <nav aria-label="Breadcrumb" className="mb-6 text-[13px] text-[var(--vc-neutral-600)]">
                <ol className="flex flex-wrap items-center gap-2">
                    {breadcrumbs.map((crumb, index) => (
                        <li key={crumb.url} className="flex items-center gap-2">
                            {index < breadcrumbs.length - 1 ? (
                                <Link href={crumb.url} className="underline underline-offset-4">
                                    {crumb.name}
                                </Link>
                            ) : (
                                <span aria-current="page">{crumb.name}</span>
                            )}
                            {index < breadcrumbs.length - 1 ? (
                                <span aria-hidden="true">/</span>
                            ) : null}
                        </li>
                    ))}
                </ol>
            </nav>

            <header className="mb-10 border-b-2 border-[var(--vc-text)] pb-6">
                <h1 className="mb-2 text-[38px] leading-[1.05]">{category.name}</h1>
                {category.description ? (
                    <p className="max-w-[62ch] text-[var(--vc-neutral-700)]">
                        {category.description}
                    </p>
                ) : null}
            </header>

            {children.length > 0 ? (
                <nav aria-label="Subcategories" className="mb-10">
                    <ul className="flex flex-wrap gap-2">
                        {children.map((child) => (
                            <li key={child.slug}>
                                <Link
                                    href={`/categories/${child.slug}`}
                                    className="inline-block border-2 border-[var(--vc-divider)] px-4 py-2 text-[14px] hover:border-[var(--vc-text)]"
                                >
                                    {child.name}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </nav>
            ) : null}

            {products.data.length === 0 ? (
                <EmptyState
                    title="Nothing listed here yet"
                    body="No seller is currently offering anything in this category. Products appear here as soon as one does."
                />
            ) : (
                <>
                    <p className="mb-4 text-[13px] text-[var(--vc-neutral-600)]">
                        {products.total} {products.total === 1 ? 'product' : 'products'}
                    </p>

                    <ul className="grid gap-[var(--vc-grid-gap)] [grid-template-columns:repeat(auto-fill,minmax(220px,1fr))]">
                        {products.data.map((product) => (
                            <li key={product.slug} className="bg-[var(--vc-surface)]">
                                <Link href={`/products/${product.slug}`} className="block p-4">
                                    {product.brand ? (
                                        <span className="mb-1 block text-[11px] tracking-[0.08em] text-[var(--vc-neutral-600)] uppercase">
                                            {product.brand}
                                        </span>
                                    ) : null}
                                    <span className="block text-[15px] font-semibold underline underline-offset-4">
                                        {product.title}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </>
            )}
        </StorefrontLayout>
    );
}
