import type { ReactNode } from 'react';
import { usePage } from '@inertiajs/react';
import { Wordmark } from '../../design-system/layout/Wordmark';
import type { SharedPageProps } from '../../shared/types';

/**
 * The chrome for someone who is not a seller yet.
 *
 * Applying and accepting an invitation are the two seller screens reachable
 * without a membership, so they must not render the portal sidebar — its
 * links would 404 for the very people looking at them.
 */
export function OnboardingLayout({
    title,
    lede,
    children,
}: {
    title: string;
    lede: string;
    children: ReactNode;
}) {
    const { platform, auth } = usePage<SharedPageProps>().props;

    return (
        <div className="min-h-screen bg-[var(--vc-bg)]">
            <header className="flex items-center gap-4 border-b-2 border-[var(--vc-text)] px-8 py-4">
                <Wordmark name={platform.name} size={17} />
                <p className="text-[11px] tracking-[0.08em] text-[var(--vc-neutral-600)] uppercase">
                    Sell on {platform.name}
                </p>
                {auth.user ? (
                    <p className="ml-auto text-[12px] text-[var(--vc-neutral-600)]">
                        {auth.user.email}
                    </p>
                ) : null}
            </header>

            <main className="mx-auto max-w-[900px] px-8 py-12">
                <h1 className="mb-3 text-[44px] leading-[1.05]">{title}</h1>
                <p className="mb-8 max-w-[62ch] text-[var(--vc-neutral-700)]">{lede}</p>
                {children}
            </main>
        </div>
    );
}
