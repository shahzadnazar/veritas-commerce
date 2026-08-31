import type { ButtonHTMLAttributes, ReactNode } from 'react';

type Variant = 'primary' | 'secondary' | 'ghost' | 'destructive';

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
    variant?: Variant;
    block?: boolean;
    loading?: boolean;
    /** Shown while loading — the present participle, e.g. "Placing order…". */
    loadingLabel?: string;
    children: ReactNode;
}

/**
 * Rules carried from the design system, not preferences:
 *
 * - Labels are flush left, including in a full-width button. Nothing is
 *   centred.
 * - Destructive never takes the solid accent fill at rest; it earns that
 *   only on the confirm button inside its confirmation dialog.
 * - Loading swaps the label to the present participle and HOLDS THE WIDTH,
 *   so the layout does not jump.
 * - Radius is 0. Everywhere. No exceptions.
 */
const VARIANTS: Record<Variant, string> = {
    primary:
        'bg-[var(--vc-accent)] text-white hover:bg-[var(--vc-accent-600)] active:bg-[var(--vc-accent-700)]',
    secondary:
        'bg-transparent text-[var(--vc-text)] border-2 border-[var(--vc-text)] hover:bg-[var(--vc-surface)]',
    ghost: 'bg-transparent text-[var(--vc-text)] hover:bg-[var(--vc-surface)] underline underline-offset-4',
    destructive:
        'bg-transparent text-[var(--vc-accent-800)] border-2 border-[var(--vc-accent-800)] hover:bg-[var(--vc-accent-100)]',
};

export function Button({
    variant = 'secondary',
    block = false,
    loading = false,
    loadingLabel,
    children,
    className = '',
    disabled,
    ...rest
}: ButtonProps) {
    return (
        <button
            {...rest}
            disabled={disabled ?? loading}
            aria-busy={loading || undefined}
            className={[
                'inline-flex items-center justify-start gap-2 px-4 py-[10px]',
                'min-h-[44px] text-left text-[14px] font-semibold',
                'transition-colors disabled:cursor-not-allowed disabled:opacity-45',
                VARIANTS[variant],
                block ? 'w-full' : '',
                className,
            ].join(' ')}
        >
            {loading ? (loadingLabel ?? children) : children}
        </button>
    );
}
