import type { CartIssue, IssueMessage } from '../../shared/commerce';

/**
 * What changed in a basket, said out loud.
 *
 * A price that moved or a line that was dropped is not a detail to tuck
 * away — a customer who reaches a payment page and finds a different total
 * than the one they agreed to has been ambushed by their own shop. So
 * these are always shown, never collapsed behind a chevron, and always in
 * words: the codes underneath are for the code.
 *
 * Blocking and advisory are distinguished by border weight and by an
 * explicit label, never by colour alone — the palette is mono, and a
 * notice that relied on hue would be invisible to the people it most
 * needs to reach.
 */

interface IssueNoticeProps {
    messages: IssueMessage[];
    /** Rendered above the list. Say what happened, not "Notice". */
    heading: string;
    /** `alert` interrupts; `status` is announced politely. */
    live?: 'alert' | 'status';
}

export function IssueNotice({ messages, heading, live = 'status' }: IssueNoticeProps) {
    if (messages.length === 0) {
        return null;
    }

    const blocking = messages.some((message) => message.blocking);

    return (
        <section
            role={live}
            aria-live={live === 'alert' ? 'assertive' : 'polite'}
            className={[
                'mb-8 px-5 py-4',
                blocking
                    ? 'border-2 border-[var(--vc-accent)]'
                    : 'border-2 border-[var(--vc-neutral-400)]',
            ].join(' ')}
        >
            <h2 className="mb-3 text-[16px]">{heading}</h2>

            <ul className="flex flex-col gap-3">
                {messages.map((message, index) => (
                    <li key={`${message.code}-${index}`} className="text-[14px]">
                        <span className="font-semibold">{message.title}</span>
                        <span className="sr-only">
                            {message.blocking
                                ? ' — must be resolved before checkout'
                                : ' — for your information'}
                        </span>
                        <span aria-hidden="true" className="text-[var(--vc-neutral-600)]">
                            {message.blocking ? ' · action needed' : ' · for information'}
                        </span>
                        <p className="text-[var(--vc-neutral-700)]">{message.detail}</p>
                    </li>
                ))}
            </ul>
        </section>
    );
}

/**
 * A single line's issues, shown inline against the line they belong to.
 *
 * Short form: the notice at the top of the page carries the explanation,
 * this is the marker that says which row it was about.
 */
export function LineIssues({ issues }: { issues: CartIssue[] }) {
    if (issues.length === 0) {
        return null;
    }

    return (
        <ul className="mt-2 flex flex-col gap-1">
            {issues.map((issue, index) => (
                <li
                    key={`${issue.code}-${index}`}
                    className={[
                        'text-[12px]',
                        issue.blocking
                            ? 'font-semibold text-[var(--vc-accent-800)]'
                            : 'text-[var(--vc-neutral-700)]',
                    ].join(' ')}
                >
                    {issue.blocking ? '▲ ' : '• '}
                    {issue.label}
                </li>
            ))}
        </ul>
    );
}
