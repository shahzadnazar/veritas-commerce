import type { StatusDomain } from '../statusTone';
import { statusPresentation } from '../statusTone';

interface StatusBadgeProps {
    domain: StatusDomain;
    value: string;
    className?: string;
}

/**
 * Four semantic fills, no hue.
 *
 * Critical is an accent tint with deep accent text (the base accent is
 * chrome-only below 18px — it clears 3:1, not 4.5:1). Pending is a dashed
 * outline with no fill. Neutral is ink on surface: in a mono system, done
 * is quiet. Inactive drops to 45%.
 */
const TONE_CLASSES: Record<string, string> = {
    neutral: 'bg-[var(--vc-surface)] text-[var(--vc-text)]',
    pending: 'border border-dashed border-[var(--vc-neutral-400)] text-[var(--vc-neutral-700)]',
    critical: 'bg-[var(--vc-accent-100)] text-[var(--vc-accent-800)]',
    inactive: 'bg-[var(--vc-surface)] text-[var(--vc-text)] opacity-45',
};

export function StatusBadge({ domain, value, className = '' }: StatusBadgeProps) {
    const { tone, label } = statusPresentation(domain, value);

    return (
        <span
            data-tone={tone}
            className={`inline-block px-2 py-[3px] text-[11px] font-semibold tracking-[0.04em] whitespace-nowrap ${TONE_CLASSES[tone]} ${className}`}
        >
            {label}
        </span>
    );
}
