import type {
    InputHTMLAttributes,
    ReactNode,
    SelectHTMLAttributes,
    TextareaHTMLAttributes,
} from 'react';
import { useId } from 'react';

interface FieldProps {
    label: string;
    /** Rendered under the field as a 2px accent border plus this message. */
    error?: string | undefined;
    hint?: string | undefined;
    children: (props: {
        id: string;
        describedBy: string | undefined;
        invalid: boolean;
    }) => ReactNode;
}

/**
 * Validation is on blur, then live once a field has errored.
 *
 * An error is a 2px accent border AND a message beneath — never a tooltip,
 * and never colour alone, which would leave the error invisible to anyone
 * who cannot distinguish it.
 */
export function Field({ label, error, hint, children }: FieldProps) {
    const id = useId();
    const errorId = `${id}-error`;
    const hintId = `${id}-hint`;
    const describedBy = error ? errorId : hint ? hintId : undefined;

    return (
        <div className="flex flex-col gap-[6px]">
            <label htmlFor={id} className="text-[12px] text-[var(--vc-neutral-700)]">
                {label}
            </label>

            {children({ id, describedBy, invalid: Boolean(error) })}

            {error ? (
                <p id={errorId} role="alert" className="text-[12px] text-[var(--vc-accent-800)]">
                    {error}
                </p>
            ) : hint ? (
                <p id={hintId} className="text-[12px] text-[var(--vc-neutral-600)]">
                    {hint}
                </p>
            ) : null}
        </div>
    );
}

const CONTROL_BASE =
    'w-full bg-[var(--vc-surface)] px-3 py-[10px] text-[14px] text-[var(--vc-text)] ' +
    'border-2 min-h-[44px] disabled:opacity-45 disabled:cursor-not-allowed';

function borderFor(invalid: boolean): string {
    return invalid
        ? 'border-[var(--vc-accent)]'
        : 'border-transparent focus:border-[var(--vc-neutral-500)]';
}

type InputProps = InputHTMLAttributes<HTMLInputElement> & { invalid?: boolean };

export function Input({ invalid = false, className = '', ...rest }: InputProps) {
    return (
        <input
            {...rest}
            aria-invalid={invalid || undefined}
            className={`${CONTROL_BASE} ${borderFor(invalid)} ${className}`}
        />
    );
}

type TextareaProps = TextareaHTMLAttributes<HTMLTextAreaElement> & { invalid?: boolean };

export function Textarea({ invalid = false, className = '', ...rest }: TextareaProps) {
    return (
        <textarea
            {...rest}
            aria-invalid={invalid || undefined}
            className={`${CONTROL_BASE} min-h-[96px] ${borderFor(invalid)} ${className}`}
        />
    );
}

type SelectProps = SelectHTMLAttributes<HTMLSelectElement> & { invalid?: boolean };

export function Select({ invalid = false, className = '', children, ...rest }: SelectProps) {
    return (
        <select
            {...rest}
            aria-invalid={invalid || undefined}
            className={`${CONTROL_BASE} ${borderFor(invalid)} ${className}`}
        >
            {children}
        </select>
    );
}
