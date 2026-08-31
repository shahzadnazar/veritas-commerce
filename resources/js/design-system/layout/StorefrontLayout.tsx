import { Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import type { SharedPageProps } from '../../shared/types';
import { Wordmark } from './Wordmark';

/**
 * The customer surface: centred to 1280, 64px header, generous space.
 *
 * Runs at the comfortable density — 15px body, 24px grid gaps — which is
 * the only intentional divergence from the operating portals.
 */
export function StorefrontLayout({ children }: { children: ReactNode }) {
    const { platform } = usePage<SharedPageProps>().props;

    return (
        <div data-density="comfortable" className="min-h-screen bg-[var(--vc-bg)]">
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
                        <Link href="/orders">Orders</Link>
                        <Link href="/cart">Cart</Link>
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
