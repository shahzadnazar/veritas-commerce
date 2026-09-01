import { Head, Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import type { SharedPageProps } from '../../shared/types';
import { Wordmark } from './Wordmark';

/**
 * The customer surface: centred to 1280, 64px header, generous space.
 *
 * Runs at the comfortable density — 15px body, 24px grid gaps — which is
 * the only intentional divergence from the operating portals.
 *
 * The title lives here rather than in the Blade shell. A page-supplied
 * title alongside a shell default renders two <title> tags under SSR, and
 * a crawler takes the first — so the shell has none, and this is the one
 * place a storefront page gets one.
 */
export function StorefrontLayout({ title, children }: { title?: string; children: ReactNode }) {
    const { platform, cart } = usePage<SharedPageProps>().props;
    const cartCount = cart?.count ?? 0;

    return (
        <div data-density="comfortable" className="min-h-screen bg-[var(--vc-bg)]">
            <Head title={title === undefined ? platform.name : `${title} — ${platform.name}`} />

            <header className="border-b-2 border-[var(--vc-text)]">
                <div className="mx-auto flex h-[var(--vc-header-storefront)] max-w-[var(--vc-container-storefront)] items-center gap-6 px-8">
                    <Link href="/" aria-label={`${platform.name} home`}>
                        <Wordmark name={platform.name} />
                    </Link>

                    <nav
                        aria-label="Primary"
                        className="ml-auto flex items-center gap-5 text-[14px]"
                    >
                        <Link href="/sell">Sell on {platform.name.split(' ')[0]}</Link>
                        <Link href="/account/orders">Orders</Link>
                        {/*
                         * The count comes from the server on every load —
                         * never from anything the browser has been
                         * keeping. A badge that said "3" over a basket
                         * revalidation had emptied would be the interface
                         * lying to the person using it.
                         */}
                        <Link href="/cart">
                            Cart
                            {cartCount > 0 ? (
                                <span className="ml-1 vc-tabular" aria-hidden="true">
                                    ({cartCount})
                                </span>
                            ) : null}
                            <span className="sr-only">
                                {cartCount === 0
                                    ? ', empty'
                                    : `, ${cartCount} ${cartCount === 1 ? 'item' : 'items'}`}
                            </span>
                        </Link>
                    </nav>
                </div>
            </header>

            <main className="mx-auto max-w-[var(--vc-container-storefront)] px-8 py-14">
                {children}
            </main>

            <footer className="border-t-2 border-[var(--vc-text)]">
                <div className="mx-auto max-w-[var(--vc-container-storefront)] px-8 py-8 text-[13px] text-[var(--vc-neutral-700)]">
                    <Wordmark name={platform.name} size={14} />
                    <p className="mt-2">
                        Prices in {platform.currency}. Questions? {platform.supportEmail}
                    </p>
                </div>
            </footer>
        </div>
    );
}
