import { Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import type { SharedPageProps } from '../../shared/types';
import { Wordmark } from './Wordmark';

export interface NavItem {
    label: string;
    href: string;
    /** The one number a person must act on, e.g. orders awaiting packing. */
    badge?: number;
}

interface PortalLayoutProps {
    area: 'Seller portal' | 'Admin';
    nav: NavItem[];
    title: string;
    actions?: ReactNode;
    identity: { primary: string; secondary: string };
    children: ReactNode;
}

/**
 * The shared chrome for both operating portals: 240px sidebar, 56px
 * page-title bar, compact density.
 *
 * Seller and admin compose the same primitives differently — the admin
 * groups its navigation, the seller's is flat — but neither owns its own
 * copy of the layout.
 */
export function PortalLayout({ area, nav, title, actions, identity, children }: PortalLayoutProps) {
    const { platform } = usePage<SharedPageProps>().props;

    return (
        <div data-density="compact" className="flex min-h-screen bg-[var(--vc-bg)]">
            <aside className="flex w-[var(--vc-sidebar)] shrink-0 flex-col border-r-2 border-[var(--vc-text)]">
                <div className="border-b-2 border-[var(--vc-text)] px-5 py-4">
                    <Wordmark name={platform.name} size={15} />
                    <p className="mt-1 text-[11px] tracking-[0.08em] text-[var(--vc-neutral-600)] uppercase">
                        {area}
                    </p>
                </div>

                <nav aria-label={area} className="flex flex-col p-2">
                    {nav.map((item) => (
                        <Link
                            key={item.href}
                            href={item.href}
                            className="flex min-h-[44px] items-center justify-between px-3 py-2 text-[14px] hover:bg-[var(--vc-surface)]"
                        >
                            <span>{item.label}</span>
                            {item.badge ? (
                                <span className="vc-tabular bg-[var(--vc-accent-100)] px-[6px] text-[11px] font-semibold text-[var(--vc-accent-800)]">
                                    {item.badge}
                                </span>
                            ) : null}
                        </Link>
                    ))}
                </nav>

                <div className="mt-auto border-t-2 border-[var(--vc-divider)] px-5 py-4 text-[12px]">
                    <p className="font-semibold">{identity.primary}</p>
                    <p className="text-[var(--vc-neutral-600)]">{identity.secondary}</p>
                </div>
            </aside>

            <div className="flex min-w-0 flex-1 flex-col">
                <header className="flex h-[var(--vc-header-portal)] items-center gap-4 border-b-2 border-[var(--vc-text)] px-10">
                    <h1 className="text-[20px]">{title}</h1>
                    {actions ? <div className="ml-auto flex gap-2">{actions}</div> : null}
                </header>

                <main className="min-w-0 flex-1 px-10 py-8">{children}</main>
            </div>
        </div>
    );
}
