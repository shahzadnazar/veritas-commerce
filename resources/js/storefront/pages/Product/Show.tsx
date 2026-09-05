import { Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { StorefrontLayout } from '../../../design-system/layout/StorefrontLayout';
import { Button } from '../../../design-system/primitives/Button';
import { EmptyState } from '../../../design-system/patterns/States';
import { StructuredData } from '../../../design-system/patterns/StructuredData';
import {
    ProductReviews,
    type RatingSummaryData,
    type ReviewsData,
} from '../../components/ProductReviews';
import {
    RecommendationShelf,
    type RecommendationSetData,
} from '../../components/RecommendationShelf';
import { WishlistButton } from '../../components/WishlistButton';
import type { SharedPageProps } from '../../../shared/types';

interface Crumb {
    name: string;
    url: string;
}

interface ProductImage {
    url: string;
    alt: string;
    isPrimary: boolean;
    width: number | null;
    height: number | null;
}

interface OfferRow {
    publicId: string;
    price: string;
    priceMinor: number;
    compareAtPrice: string | null;
    currency: string;
    condition: string;
    conditionLabel: string;
    handlingDays: number;
    variantPublicId: string | null;
    seller: { storeName: string; storeSlug: string | null };
}

interface VariantRow {
    publicId: string;
    name: string;
    options: Record<string, string>;
    hasOffer: boolean;
}

interface ProductShowProps extends SharedPageProps {
    product: {
        publicId: string;
        title: string;
        slug: string;
        description: string | null;
        brand: { name: string; slug: string } | null;
        category: { name: string; slug: string } | null;
        identifiers: Record<string, string>;
    };
    breadcrumbs: Crumb[];
    media: ProductImage[];
    specifications: { name: string; value: string }[];
    variants: VariantRow[];
    offers: OfferRow[];
    featuredOfferPublicId: string | null;
    priceRange: {
        from: string;
        to: string;
        currency: string;
        isSingle: boolean;
    } | null;
    offerCount: number;
    seo: {
        title: string;
        description: string;
        canonical: string;
        robots: string;
        ogTitle: string;
        ogType: string;
        ogUrl: string;
        ogImage: string | null;
    };
    structuredData: unknown[];
    rating: RatingSummaryData;
    reviews: ReviewsData;
    wishlist: { isAuthenticated: boolean; isSaved: boolean };
    shelves: Record<string, RecommendationSetData>;
}

/**
 * The canonical product page.
 *
 * Product photography is full colour — the monochrome of the design system
 * is chrome, never the goods. Cart is M4, so the action is honestly
 * disabled rather than pretending to work.
 */
export default function Show() {
    const {
        product,
        breadcrumbs,
        media,
        specifications,
        variants,
        offers,
        priceRange,
        seo,
        structuredData,
        rating,
        reviews,
        wishlist,
        shelves,
    } = usePage<ProductShowProps>().props;

    const [selectedImage, setSelectedImage] = useState(0);
    const [selectedVariant, setSelectedVariant] = useState<string | null>(null);

    const visibleOffers = selectedVariant
        ? offers.filter((offer) => offer.variantPublicId === selectedVariant)
        : offers;

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
                {seo.ogImage ? <meta property="og:image" content={seo.ogImage} /> : null}
            </Head>

            <StructuredData documents={structuredData} />

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

            <div className="grid gap-10 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                <section aria-label="Product images">
                    {media.length === 0 ? (
                        <div className="flex aspect-square items-center justify-center border-2 border-[var(--vc-divider)] text-[13px] text-[var(--vc-neutral-600)]">
                            No photographs yet
                        </div>
                    ) : (
                        <>
                            <img
                                src={media[selectedImage]?.url ?? media[0]?.url}
                                alt={media[selectedImage]?.alt ?? product.title}
                                width={media[selectedImage]?.width ?? undefined}
                                height={media[selectedImage]?.height ?? undefined}
                                className="aspect-square w-full bg-[var(--vc-surface)] object-contain"
                            />

                            {media.length > 1 ? (
                                <ul className="mt-3 flex flex-wrap gap-2">
                                    {media.map((image, index) => (
                                        <li key={image.url}>
                                            <button
                                                type="button"
                                                aria-label={`Show image ${index + 1} of ${media.length}`}
                                                aria-current={index === selectedImage}
                                                onClick={() => setSelectedImage(index)}
                                                className={[
                                                    'h-[64px] w-[64px] border-2 p-[2px]',
                                                    index === selectedImage
                                                        ? 'border-[var(--vc-text)]'
                                                        : 'border-transparent hover:border-[var(--vc-neutral-400)]',
                                                ].join(' ')}
                                            >
                                                <img
                                                    src={image.url}
                                                    alt=""
                                                    className="h-full w-full object-contain"
                                                />
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            ) : null}
                        </>
                    )}
                </section>

                <section>
                    {product.brand ? (
                        <p className="mb-1 text-[13px] tracking-[0.08em] text-[var(--vc-neutral-600)] uppercase">
                            {product.brand.name}
                        </p>
                    ) : null}

                    <h1 className="mb-4 text-[38px] leading-[1.1]">{product.title}</h1>

                    {priceRange ? (
                        <p className="vc-tabular mb-6 text-[28px] font-extrabold">
                            {priceRange.isSingle
                                ? priceRange.from
                                : `${priceRange.from} – ${priceRange.to}`}
                            <span className="ml-2 align-middle text-[13px] font-normal text-[var(--vc-neutral-600)]">
                                from {offers.length} {offers.length === 1 ? 'seller' : 'sellers'}
                            </span>
                        </p>
                    ) : null}

                    {product.description ? (
                        <p className="mb-6 max-w-[62ch] text-[var(--vc-neutral-700)]">
                            {product.description}
                        </p>
                    ) : null}

                    {variants.length > 0 ? (
                        <fieldset className="mb-6">
                            <legend className="mb-2 text-[12px] text-[var(--vc-neutral-700)]">
                                Choose an option
                            </legend>
                            <div className="flex flex-wrap gap-2">
                                {variants.map((variant) => (
                                    <button
                                        key={variant.publicId}
                                        type="button"
                                        disabled={!variant.hasOffer}
                                        aria-pressed={selectedVariant === variant.publicId}
                                        onClick={() =>
                                            setSelectedVariant(
                                                selectedVariant === variant.publicId
                                                    ? null
                                                    : variant.publicId,
                                            )
                                        }
                                        className={[
                                            'min-h-[44px] border-2 px-4 py-2 text-[14px]',
                                            selectedVariant === variant.publicId
                                                ? 'border-[var(--vc-text)] bg-[var(--vc-surface)]'
                                                : 'border-[var(--vc-divider)]',
                                            variant.hasOffer
                                                ? 'hover:border-[var(--vc-text)]'
                                                : 'cursor-not-allowed opacity-45',
                                        ].join(' ')}
                                    >
                                        {variant.name}
                                        {variant.hasOffer ? '' : ' — unavailable'}
                                    </button>
                                ))}
                            </div>
                        </fieldset>
                    ) : null}

                    <h2 className="mb-3 text-[20px]">
                        {visibleOffers.length === 1 ? 'Seller' : 'Sellers'}
                    </h2>

                    {visibleOffers.length === 0 ? (
                        <EmptyState
                            title="No seller is listing this right now"
                            body="Nobody currently offers this product. It stays in the catalogue, so a listing can appear again at any time."
                        />
                    ) : (
                        <ul className="border-t-2 border-[var(--vc-text)]">
                            {visibleOffers.map((offer) => (
                                <li
                                    key={offer.publicId}
                                    className="flex flex-wrap items-center gap-4 border-b border-[var(--vc-divider)] py-4"
                                >
                                    <span className="flex-1">
                                        <span className="vc-tabular block text-[20px] font-bold">
                                            {offer.price}
                                        </span>
                                        <span className="block text-[13px] text-[var(--vc-neutral-600)]">
                                            {offer.conditionLabel} ·{' '}
                                            {offer.seller.storeSlug ? (
                                                <Link
                                                    href={`/stores/${offer.seller.storeSlug}`}
                                                    className="underline underline-offset-4"
                                                >
                                                    {offer.seller.storeName}
                                                </Link>
                                            ) : (
                                                offer.seller.storeName
                                            )}{' '}
                                            · dispatches in {offer.handlingDays}{' '}
                                            {offer.handlingDays === 1 ? 'day' : 'days'}
                                        </span>
                                    </span>

                                    {/* Cart arrives in M4. Disabled and labelled, rather
                                        than a button that pretends to work. */}
                                    <Button variant="secondary" disabled title="Buying opens soon">
                                        Buying opens soon
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>

            <div className="mt-10">
                <WishlistButton
                    productPublicId={product.publicId}
                    isSaved={wishlist.isSaved}
                    isAuthenticated={wishlist.isAuthenticated}
                />
            </div>

            {specifications.length > 0 ? (
                <section className="mt-12 max-w-[720px]">
                    <h2 className="mb-4 text-[22px]">Specifications</h2>
                    <dl className="border-t-2 border-[var(--vc-text)]">
                        {specifications.map((specification) => (
                            <div
                                key={specification.name}
                                className="flex gap-6 border-b border-[var(--vc-divider)] py-3 text-[14px]"
                            >
                                <dt className="w-[220px] shrink-0 text-[var(--vc-neutral-600)]">
                                    {specification.name}
                                </dt>
                                <dd>{specification.value}</dd>
                            </div>
                        ))}
                    </dl>
                </section>
            ) : null}

            <ProductReviews
                productPublicId={product.publicId}
                reviews={reviews}
                rating={rating}
            />

            {/*
              * Shelves in the order the server listed them, and each one
              * already excludes what the one above it showed — a product
              * cannot appear twice down the page.
              */}
            {Object.values(shelves).map((shelf) => (
                <RecommendationShelf key={shelf.slot} set={shelf} />
            ))}
        </StorefrontLayout>
    );
}
