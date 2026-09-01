import { useState } from 'react';

interface QuantityStepperProps {
    value: number;
    /** The server's current ceiling. A courtesy — the refusal is authority. */
    max: number;
    disabled?: boolean;
    busy?: boolean;
    label: string;
    onChange: (quantity: number) => void;
}

/**
 * A quantity control that never pretends to know the inventory.
 *
 * The number here is a request. It is sent to the server, the server locks
 * the row and decides, and whatever comes back is the truth — so this
 * component holds no optimistic count and reconciles to the prop whenever
 * it changes. A stepper that quietly kept its own idea of "3" after the
 * server said 2 would be the most expensive kind of lie a shop can tell.
 *
 * `max` disables the increment early as a courtesy. It is not the control:
 * a customer who edits the field, or whose stock moved a second ago, is
 * refused by the action.
 *
 * Keyboard: the two buttons are buttons and the field is a number input,
 * so tab, arrows and typing all work without any handler of ours.
 */
export function QuantityStepper({
    value,
    max,
    disabled = false,
    busy = false,
    label,
    onChange,
}: QuantityStepperProps) {
    const [draft, setDraft] = useState(String(value));
    const [settled, setSettled] = useState(value);

    /*
     * The server's number wins whenever it changes underneath us.
     *
     * Adjusted during render rather than in an effect: an effect would
     * paint the stale number first and then correct it, which on a slow
     * connection is a quantity that visibly flickers back — exactly the
     * moment a customer starts distrusting the total.
     */
    if (value !== settled) {
        setSettled(value);
        setDraft(String(value));
    }

    const commit = (next: number) => {
        if (!Number.isFinite(next) || next === value) {
            setDraft(String(value));

            return;
        }

        onChange(Math.max(0, Math.trunc(next)));
    };

    return (
        <div className="inline-flex items-stretch border-2 border-[var(--vc-text)]">
            <button
                type="button"
                className="px-3 text-[16px] leading-none disabled:opacity-45"
                disabled={disabled || busy || value <= 1}
                aria-label={`Decrease quantity of ${label}`}
                onClick={() => commit(value - 1)}
            >
                −
            </button>

            <input
                type="number"
                inputMode="numeric"
                min={1}
                max={Math.max(max, 1)}
                value={draft}
                disabled={disabled || busy}
                aria-label={`Quantity of ${label}`}
                aria-busy={busy || undefined}
                className="vc-tabular w-[3.5rem] border-x-2 border-[var(--vc-text)] bg-transparent py-2 text-center text-[14px] disabled:opacity-45"
                onChange={(event) => setDraft(event.target.value)}
                onBlur={() => commit(Number(draft))}
                onKeyDown={(event) => {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        commit(Number(draft));
                    }
                }}
            />

            <button
                type="button"
                className="px-3 text-[16px] leading-none disabled:opacity-45"
                disabled={disabled || busy || value >= max}
                aria-label={`Increase quantity of ${label}`}
                onClick={() => commit(value + 1)}
            >
                +
            </button>
        </div>
    );
}
