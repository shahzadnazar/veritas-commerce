import { Link } from '@inertiajs/react';
import { StatusBadge } from '../primitives/StatusBadge';

export interface ProductCardData {
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
}

/**
 * One product, everywhere it is listed.
 *
 * Search, category pages and store pages all render this — §29 forbids
 * three implementations that drift. It computes nothing: the price string,
 * the range and the stock state all arrive decided by the server, so a
 * card and the product page it links to cannot quote different numbers.
 *
 * Commerce photography stays in full colour; the Modernist system's
 * greyscale applies to chrome, not to the goods.
 */
export function ProductCard({
    product,
    position,
    onSelect,
}: {
    product: ProductCardData;
    position?: number;
    onSelect?: (product: ProductCardData, position: number) => void;
}) {
    const outOfStock = product.stockState === 'out_of_stock';

    return (
        <article className="flex flex-col">
            <Link
                href={`/products/${product.slug}`}
                className="group flex flex-col gap-3"
                onClick={() => {
                    if (onSelect && position !== undefined) {
                        onSelect(product, position);
                    }
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
                        <span className="text-[13px] text-[var(--vc-neutral-600)]">
                            No sellers yet
                        </span>
                    ) : (
                        <span className="vc-tabular text-[15px]">
                            {product.hasPriceRange ? 'From ' : ''}
                            {product.price}
                        </span>
                    )}

                    <div className="mt-1 flex flex-wrap items-center gap-2">
                        {outOfStock ? (
                            <StatusBadge domain="stock" value={product.stockState} />
                        ) : null}
                        {product.offerCount > 1 ? (
                            <span className="text-[12px] text-[var(--vc-neutral-600)]">
                                {product.offerCount} sellers
                            </span>
                        ) : null}
                    </div>
                </div>
            </Link>
        </article>
    );
}

/** The grid every listing page lays its cards out on. */
export function ProductGrid({
    products,
    onSelect,
}: {
    products: ProductCardData[];
    onSelect?: (product: ProductCardData, position: number) => void;
}) {
    return (
        <div className="grid grid-cols-2 gap-x-6 gap-y-10 md:grid-cols-3 lg:grid-cols-4">
            {products.map((product, index) => (
                <ProductCard
                    key={product.slug}
                    product={product}
                    position={index + 1}
                    {...(onSelect ? { onSelect } : {})}
                />
            ))}
        </div>
    );
}
