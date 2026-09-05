import { Link, router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';

export interface RecommendedProductData {
    productId: number;
    slug: string;
    title: string;
    brand: string | null;
    imageUrl: string | null;
    imageAlt: string | null;
    price: string;
    hasPriceRange: boolean;
    offerCount: number;
    stockState: string;
    stockLabel: string;
    ratingAverage: number | null;
    ratingCount: number;
    reason: string;
}

export interface RecommendationSetData {
    slot: string;
    title: string;
    products: RecommendedProductData[];
    strategies: string[];
    usedFallback: boolean;
}

/**
 * One shelf of recommendations.
 *
 * Renders nothing at all when the set is empty — §39's fallback chain has
 * already tried everything it can, and an empty carousel with a heading
 * above it is worse than no heading. The component never decides *what* to
 * show: the products, their order and the heading all arrive from the
 * server, so a shelf cannot quietly become a different shelf in the
 * browser.
 *
 * Impressions are recorded once per mount, and only for products actually
 * rendered, so the click-through rate on the analytics page has a
 * denominator that means something. The beacon is fire-and-forget: a
 * failed analytics call must never break a page a customer is reading.
 */
export function RecommendationShelf({ set }: { set: RecommendationSetData | null | undefined }) {
    const recorded = useRef(false);

    const products = set?.products ?? [];
    const slot = set?.slot ?? '';

    useEffect(() => {
        if (recorded.current || products.length === 0 || slot === '') {
            return;
        }

        recorded.current = true;

        void fetch('/recommendations/impressions', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({
                slot,
                products: products.map((product) => product.slug),
            }),
            keepalive: true,
        }).catch(() => {
            /* Analytics must never break the page it is measuring. */
        });
    }, [products, slot]);

    if (!set || products.length === 0) {
        return null;
    }

    return (
        <section className="mt-16 border-t-2 border-[var(--vc-text)] pt-8" aria-labelledby={`shelf-${set.slot}`}>
            <h2 id={`shelf-${set.slot}`} className="mb-8 text-[24px]">
                {set.title}
            </h2>

            <div className="grid grid-cols-2 gap-x-6 gap-y-10 md:grid-cols-3 lg:grid-cols-4">
                {products.map((product, index) => (
                    <RecommendationCard
                        key={product.productId}
                        product={product}
                        slot={set.slot}
                        position={index + 1}
                    />
                ))}
            </div>
        </section>
    );
}

function RecommendationCard({
    product,
    slot,
    position,
}: {
    product: RecommendedProductData;
    slot: string;
    position: number;
}) {
    return (
        <article className="flex flex-col">
            <Link
                href={`/products/${product.slug}`}
                className="group flex flex-col gap-3"
                onClick={() => {
                    void fetch('/recommendations/clicks', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': csrfToken(),
                        },
                        body: JSON.stringify({ slot, product: product.slug, position }),
                        keepalive: true,
                    }).catch(() => {
                        /* See above. */
                    });
                }}
            >
                <div className="aspect-square border-2 border-[var(--vc-text)] bg-[var(--vc-surface)]">
                    {product.imageUrl ? (
                        <img
                            src={product.imageUrl}
                            alt={product.imageAlt ?? ''}
                            loading="lazy"
                            className="h-full w-full object-cover"
                        />
                    ) : (
                        <div className="flex h-full w-full items-center justify-center text-[12px] text-[var(--vc-neutral-600)]">
                            No photograph yet
                        </div>
                    )}
                </div>

                <div className="flex flex-col gap-1">
                    {product.brand ? (
                        <span className="text-[11px] tracking-[0.08em] text-[var(--vc-neutral-600)] uppercase">
                            {product.brand}
                        </span>
                    ) : null}

                    <h3 className="text-[15px] leading-snug font-semibold underline-offset-4 group-hover:underline">
                        {product.title}
                    </h3>

                    {product.price === '' ? (
                        <span className="text-[13px] text-[var(--vc-neutral-600)]">No sellers yet</span>
                    ) : (
                        <span className="vc-tabular text-[15px]">
                            {product.hasPriceRange ? 'From ' : ''}
                            {product.price}
                        </span>
                    )}

                    <RatingLine average={product.ratingAverage} count={product.ratingCount} />
                </div>
            </Link>
        </article>
    );
}

/**
 * The rating, or nothing.
 *
 * A product with no published reviews shows no stars rather than an empty
 * five — an unrated product and a badly rated one must never look alike.
 */
export function RatingLine({ average, count }: { average: number | null; count: number }) {
    if (average === null || count < 1) {
        return null;
    }

    return (
        <span className="text-[12px] text-[var(--vc-neutral-600)]">
            <span className="vc-tabular">{average.toFixed(1)}</span>
            <span aria-hidden="true"> ★ </span>
            <span className="sr-only"> out of 5, </span>
            {count} {count === 1 ? 'review' : 'reviews'}
        </span>
    );
}

/** Reload the current page's props without a full navigation. */
export function refreshProps(only: string[]) {
    router.reload({ only });
}

function csrfToken(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}
