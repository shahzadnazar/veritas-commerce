import type { ReactNode } from 'react';

interface AuthCardProps {
    title: string;
    lede: string;
    status?: string | undefined;
    children: ReactNode;
    footer?: ReactNode;
}

/**
 * The shell every credential screen sits in.
 *
 * One component so registration, sign-in, reset and verification cannot
 * drift apart in spacing, heading scale or focus behaviour.
 */
export function AuthCard({ title, lede, status, children, footer }: AuthCardProps) {
    return (
        <div className="max-w-[420px]">
            <h1 className="mb-3 text-[44px] leading-[1.05]">{title}</h1>
            <p className="mb-7 text-[var(--vc-neutral-700)]">{lede}</p>

            {status ? (
                <p role="status" className="mb-6 border-2 border-[var(--vc-text)] px-4 py-3 text-[14px]">
                    {status}
                </p>
            ) : null}

            {children}

            {footer ? <div className="mt-6 text-[13px] text-[var(--vc-neutral-700)]">{footer}</div> : null}
        </div>
    );
}
