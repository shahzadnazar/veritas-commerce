import { Head, Link, router, usePage } from '@inertiajs/react';
import { StorefrontLayout } from '../../../design-system/layout/StorefrontLayout';
import { StatusBadge } from '../../../design-system/primitives/StatusBadge';
import {
    RatingLine,
    RecommendationShelf,
    type RecommendationSetData,
} from '../../components/RecommendationShelf';
import type { SharedPageProps } from '../../../shared/types';

interface WishlistItemData {
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
    isAvailable: boolean;
    ratingAverage: number | null;
    ratingCount: number;
    savedAt: string;
}

interface WishlistProps extends SharedPageProps {
    items: WishlistItemData[];
    suggestions: RecommendationSetData | null;
    status?: string;
}

/**
 * The customer's saved products.
 *
 * A withdrawn product stays on the list, marked unavailable, rather than
 * disappearing: a list that quietly shrinks makes the customer think the
 * site lost something, and "no longer sold" is information they can act
 * on.
 *
 * `noindex` in the markup as well as in the response header. The header is
 * what actually protects the page — it reaches a crawler even when SSR is
 * misconfigured — and this is the belt to its braces.
 */
export default function Wishlist() {
    const { items, suggestions, status } = usePage<WishlistProps>().props;

    return (
        <StorefrontLayout title="Your wishlist">
            <Head>
                <meta name="robots" content="noindex, nofollow" />
            </Head>

            <h1 className="mb-2 text-[42px]">Your wishlist</h1>
            <p className="mb-8 text-[14px] text-[var(--vc-neutral-600)]">
                {items.length === 0
                    ? 'Nothing saved yet.'
                    : `${items.length} ${items.length === 1 ? 'product' : 'products'} saved.`}
            </p>

            {status ? (
                <p role="status" className="mb-8 border-2 border-[var(--vc-text)] px-4 py-3 text-[14px]">
                    {status}
                </p>
            ) : null}

            {items.length === 0 ? (
                <p className="max-w-[52ch] text-[15px]">
                    Save a product from its page and it will wait for you here — across devices, and for
                    as long as you want it.
                </p>
            ) : (
                <ul className="grid grid-cols-2 gap-x-6 gap-y-10 md:grid-cols-3 lg:grid-cols-4">
                    {items.map((item) => (
                        <li key={item.productId} className="flex flex-col">
                            <SavedCard item={item} />
                        </li>
                    ))}
                </ul>
            )}

            <RecommendationShelf set={suggestions} />
        </StorefrontLayout>
    );
}

function SavedCard({ item }: { item: WishlistItemData }) {
    const remove = () => {
        router.delete('/account/wishlist', {
            preserveScroll: true,
            data: { product: item.slug },
        });
    };

    return (
        <article className="flex h-full flex-col gap-3">
            <Link href={`/products/${item.slug}`} className="group flex flex-col gap-3">
                <div className="aspect-square border-2 border-[var(--vc-text)] bg-[var(--vc-surface)]">
                    {item.imageUrl ? (
                        <img
                            src={item.imageUrl}
                            alt={item.imageAlt ?? ''}
                            loading="lazy"
                            className={`h-full w-full object-cover ${item.isAvailable ? '' : 'opacity-40'}`}
                        />
                    ) : (
                        <div className="flex h-full w-full items-center justify-center text-[12px] text-[var(--vc-neutral-600)]">
                            No photograph yet
                        </div>
                    )}
                </div>

                <div className="flex flex-col gap-1">
                    {item.brand ? (
                        <span className="text-[11px] tracking-[0.08em] text-[var(--vc-neutral-600)] uppercase">
                            {item.brand}
                        </span>
                    ) : null}

                    <h2 className="text-[15px] leading-snug font-semibold underline-offset-4 group-hover:underline">
                        {item.title}
                    </h2>

                    {item.isAvailable ? (
                        <span className="vc-tabular text-[15px]">
                            {item.hasPriceRange ? 'From ' : ''}
                            {item.price}
                        </span>
                    ) : (
                        <span className="text-[13px] text-[var(--vc-neutral-600)]">
                            No longer sold
                        </span>
                    )}

                    <RatingLine average={item.ratingAverage} count={item.ratingCount} />

                    {item.isAvailable && item.stockState === 'out_of_stock' ? (
                        <span className="mt-1">
                            <StatusBadge domain="stock" value={item.stockState} />
                        </span>
                    ) : null}
                </div>
            </Link>

            <button
                type="button"
                onClick={remove}
                className="mt-auto self-start text-[13px] underline underline-offset-4"
            >
                Remove
            </button>
        </article>
    );
}
