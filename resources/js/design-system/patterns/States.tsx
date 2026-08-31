import type { ReactNode } from 'react';

interface StateProps {
    title: string;
    body: string;
    actions?: ReactNode;
}

/**
 * Page states are specified per screen, not generically.
 *
 * Empty never dead-ends: it names the action that resolves it. Error states
 * say what was NOT changed, because "something went wrong" leaves a person
 * wondering whether they were charged.
 */
export function EmptyState({ title, body, actions }: StateProps) {
    return (
        <div className="border-2 border-[var(--vc-divider)] px-6 py-12">
            <h3 className="mb-2 text-[20px]">{title}</h3>
            <p className="mb-5 max-w-[52ch] text-[var(--vc-neutral-700)]">{body}</p>
            {actions ? <div className="flex flex-wrap gap-2">{actions}</div> : null}
        </div>
    );
}

export function ErrorState({ title, body, actions }: StateProps) {
    return (
        <div role="alert" className="border-2 border-[var(--vc-accent)] px-6 py-12">
            <h3 className="mb-2 text-[20px] text-[var(--vc-accent-800)]">{title}</h3>
            <p className="mb-5 max-w-[52ch] text-[var(--vc-neutral-700)]">{body}</p>
            {actions ? <div className="flex flex-wrap gap-2">{actions}</div> : null}
        </div>
    );
}

export function SuccessState({ title, body, actions }: StateProps) {
    return (
        <div className="border-2 border-[var(--vc-text)] px-6 py-12">
            <h3 className="mb-2 text-[24px]">{title}</h3>
            <p className="mb-5 max-w-[52ch] text-[var(--vc-neutral-700)]">{body}</p>
            {actions ? <div className="flex flex-wrap gap-2">{actions}</div> : null}
        </div>
    );
}

/**
 * Skeletons mirror the real geometry so nothing shifts on load, and are
 * shown only after 200ms — a faster response skips them entirely, because
 * a flash of skeleton reads worse than a brief blank.
 */
export function TableSkeleton({ columns, rows = 8 }: { columns: number; rows?: number }) {
    return (
        <div aria-busy="true" aria-live="polite" className="border-t-2 border-[var(--vc-text)]">
            <span className="sr-only">Loading</span>
            {Array.from({ length: rows }).map((_, rowIndex) => (
                <div
                    key={rowIndex}
                    className="flex gap-3 border-b border-[var(--vc-divider)] px-3 py-3 first:pl-0"
                >
                    {Array.from({ length: columns }).map((__, columnIndex) => (
                        <div
                            key={columnIndex}
                            className={[
                                'h-[14px] flex-1 bg-[var(--vc-surface)]',
                                // Only the first column shimmers; a full grid
                                // of moving bars reads as noise.
                                columnIndex === 0 ? 'animate-pulse' : '',
                            ].join(' ')}
                        />
                    ))}
                </div>
            ))}
        </div>
    );
}

export function CardGridSkeleton({ count = 8 }: { count?: number }) {
    return (
        <div
            aria-busy="true"
            className="grid gap-[var(--vc-grid-gap)] [grid-template-columns:repeat(auto-fill,minmax(220px,1fr))]"
        >
            {Array.from({ length: count }).map((_, index) => (
                <div key={index} className="bg-[var(--vc-surface)]">
                    <div className="aspect-square animate-pulse bg-[var(--vc-neutral-300)]" />
                    <div className="p-3">
                        <div className="mb-2 h-[12px] w-1/2 bg-[var(--vc-neutral-300)]" />
                        <div className="h-[14px] w-4/5 bg-[var(--vc-neutral-300)]" />
                    </div>
                </div>
            ))}
        </div>
    );
}

/**
 * The one-line confirmation after a redirect, fed by the shared `flash`
 * prop. Success is announced politely; a failure is an alert, because a
 * screen-reader user must not miss that the change did not happen.
 */
export function FlashBanner({
    success,
    error,
}: {
    success?: string | undefined;
    error?: string | undefined;
}) {
    if (!success && !error) {
        return null;
    }

    return (
        <p
            role={error ? 'alert' : 'status'}
            className={[
                'mb-6 border-2 px-4 py-3 text-[14px]',
                error
                    ? 'border-[var(--vc-accent)] text-[var(--vc-accent-800)]'
                    : 'border-[var(--vc-text)]',
            ].join(' ')}
        >
            {error ?? success}
        </p>
    );
}
