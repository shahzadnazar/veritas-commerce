import { usePage } from '@inertiajs/react';
import { Button } from '../../design-system/primitives/Button';
import { EmptyState } from '../../design-system/patterns/States';
import { StorefrontLayout } from '../../design-system/layout/StorefrontLayout';
import { RecommendationShelf, type RecommendationSetData } from '../components/RecommendationShelf';
import type { SharedPageProps } from '../../shared/types';

interface HomeProps extends SharedPageProps {
    stats: { products: number; sellers: number };
    shelves: Record<string, RecommendationSetData>;
}

/**
 * The storefront shell.
 *
 * M0 delivers structure only — the catalogue rails arrive in M2. What is
 * real here is the layout, the density, the tokens and the SSR path.
 */
export default function Home() {
    const { stats, platform, shelves } = usePage<HomeProps>().props;
    const rendered = Object.values(shelves);

    return (
        <StorefrontLayout>
            <p className="mb-4 text-[11px] font-semibold tracking-[0.11em] text-[var(--vc-accent-700)] uppercase">
                {stats.sellers} independent sellers · one checkout
            </p>

            <h1 className="mb-5 max-w-[14ch] text-[60px] leading-[1.02]">
                Everything, from people who make it.
            </h1>

            <p className="mb-7 max-w-[58ch] text-[17px] text-[var(--vc-neutral-700)]">
                {platform.name} is a marketplace of independent stores — listed and shipped by the
                sellers themselves, bought in one cart.
            </p>

            <div className="mb-14 flex flex-wrap gap-2">
                <Button variant="primary">Browse everything</Button>
                <Button variant="secondary">Meet the sellers</Button>
            </div>

            {stats.products === 0 ? (
                <>
                    <hr className="mb-8 border-0 border-t-2 border-[var(--vc-text)]" />
                    <EmptyState
                        title="The catalogue is empty"
                        body="No offers have been published yet. Sellers list products from the seller portal, and they appear here once approved."
                        actions={<Button variant="secondary">Apply to sell</Button>}
                    />
                </>
            ) : null}

            {/*
             * Shelves in the order the server chose, each already
             * excluding what the one above it showed. A shelf with
             * nothing in it renders nothing at all — a heading over an
             * empty carousel is worse than no heading.
             */}
            {rendered.map((shelf) => (
                <RecommendationShelf key={shelf.slot} set={shelf} />
            ))}
        </StorefrontLayout>
    );
}
