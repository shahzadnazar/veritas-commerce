import type { ReactNode } from 'react';
import { useEffect, useRef } from 'react';

interface ModalProps {
    open: boolean;
    title: string;
    /** Say what will happen, in words, before it happens. */
    consequence: string;
    onClose: () => void;
    children?: ReactNode;
    actions: ReactNode;
}

/**
 * Modals are for short decisions and always name the consequence.
 * Anything longer than two fields becomes a drawer or a page.
 *
 * Escape closes, focus is trapped, and focus returns to whatever opened it.
 */
export function Modal({ open, title, consequence, onClose, children, actions }: ModalProps) {
    const dialogRef = useRef<HTMLDivElement>(null);
    const previouslyFocused = useRef<HTMLElement | null>(null);

    useEffect(() => {
        if (!open) {
            return;
        }

        previouslyFocused.current = document.activeElement as HTMLElement | null;
        dialogRef.current?.focus();

        function onKeyDown(event: KeyboardEvent) {
            if (event.key === 'Escape') {
                onClose();
                return;
            }

            if (event.key !== 'Tab' || !dialogRef.current) {
                return;
            }

            const focusable = dialogRef.current.querySelectorAll<HTMLElement>(
                'a[href], button:not([disabled]), textarea, input, select, [tabindex]:not([tabindex="-1"])',
            );

            if (focusable.length === 0) {
                return;
            }

            const first = focusable[0]!;
            const last = focusable[focusable.length - 1]!;

            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        }

        document.addEventListener('keydown', onKeyDown);

        return () => {
            document.removeEventListener('keydown', onKeyDown);
            previouslyFocused.current?.focus();
        };
    }, [open, onClose]);

    if (!open) {
        return null;
    }

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-[color-mix(in_srgb,#201e1d_55%,transparent)] p-4"
            onClick={onClose}
        >
            <div
                ref={dialogRef}
                role="dialog"
                aria-modal="true"
                aria-label={title}
                tabIndex={-1}
                onClick={(event) => event.stopPropagation()}
                className="w-full max-w-[520px] bg-[var(--vc-bg)] p-6 shadow-[var(--vc-shadow-lg)]"
            >
                <h2 className="mb-2 text-[22px]">{title}</h2>
                <p className="mb-5 text-[14px] text-[var(--vc-neutral-700)]">{consequence}</p>
                {children ? <div className="mb-5 flex flex-col gap-4">{children}</div> : null}
                <div className="flex flex-wrap justify-end gap-2">{actions}</div>
            </div>
        </div>
    );
}
