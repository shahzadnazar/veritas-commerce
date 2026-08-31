interface WordmarkProps {
    /** From platform settings, never hard-coded. */
    name: string;
    size?: number;
}

/**
 * VERITAS COMMERCE — the second word in the accent, matching the
 * construction the prototype uses for its wordmark. Archivo 800.
 */
export function Wordmark({ name, size = 18 }: WordmarkProps) {
    const [first, ...rest] = name.split(' ');

    return (
        <span
            style={{ fontSize: size }}
            className="font-[family-name:var(--vc-font-heading)] font-extrabold tracking-[0.02em] whitespace-nowrap"
        >
            {first}
            {rest.length > 0 ? (
                <span className="text-[var(--vc-accent)]"> {rest.join(' ')}</span>
            ) : null}
        </span>
    );
}
