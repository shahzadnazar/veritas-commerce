import { Head, usePage } from '@inertiajs/react';
import { StorefrontLayout } from '../../../design-system/layout/StorefrontLayout';
import { EmptyState } from '../../../design-system/patterns/States';
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
    seo: {
        title: string;
        description: string;
        canonical: string;
        ogTitle: string;
        ogType: string;
        ogUrl: string;
    };
}

/**
 * The public store page.
 *
 * The catalogue belongs to M2, so the product area carries an honest empty
 * state rather than placeholder cards — a page that looks finished but
 * shows nothing real is worse than one that says what it is.
 */
export default function Show() {
    const { store, seo } = usePage<StoreShowProps>().props;

    return (
        <StorefrontLayout>
            <Head>
                <title>{seo.title}</title>
                <meta name="description" content={seo.description} />
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
                    <p className="mt-5 max-w-[62ch] text-[var(--vc-neutral-700)]">{store.description}</p>
                ) : null}

                {!store.isOpen ? (
                    <p className="mt-5 border-2 border-[var(--vc-divider)] px-4 py-3 text-[14px]">
                        This store is not taking orders at the moment.
                    </p>
                ) : null}
            </header>

            <div className="grid gap-10 md:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                <section>
                    <h2 className="mb-5 text-[24px]">Products</h2>
                    <EmptyState
                        title="No products listed yet"
                        body="This seller has not published anything to the marketplace catalogue. Their listings will appear here once they do."
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
