import type { ReactNode } from 'react';

export interface Column<Row> {
    key: string;
    header: string;
    /** Money and quantities right-align and use tabular figures. */
    numeric?: boolean;
    render: (row: Row) => ReactNode;
}

interface TableProps<Row> {
    columns: Column<Row>[];
    rows: Row[];
    rowKey: (row: Row) => string | number;
    empty?: ReactNode;
    caption?: string;
}

/**
 * One table across seller and admin.
 *
 * Uppercase 11px header, 1px row rules, money right-aligned and tabular,
 * status second-from-last, row action last. An unavailable figure shows an
 * em dash, never $0.00 — a failed payment produced no commission, and
 * saying "$0.00" would claim a split that never happened.
 *
 * The wrapper scrolls, not the page.
 */
export function Table<Row>({ columns, rows, rowKey, empty, caption }: TableProps<Row>) {
    if (rows.length === 0 && empty) {
        return <>{empty}</>;
    }

    return (
        <div className="overflow-x-auto border-t-2 border-[var(--vc-text)]">
            <table className="w-full border-collapse text-[var(--vc-table-size)]">
                {caption ? <caption className="sr-only">{caption}</caption> : null}
                <thead>
                    <tr>
                        {columns.map((column) => (
                            <th
                                key={column.key}
                                scope="col"
                                className={[
                                    'border-b border-[var(--vc-divider)] px-3 py-3 first:pl-0',
                                    'text-[11px] font-semibold tracking-[0.08em] uppercase',
                                    'text-[var(--vc-neutral-600)]',
                                    column.numeric ? 'text-right' : 'text-left',
                                ].join(' ')}
                            >
                                {column.header}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <tr key={rowKey(row)} className="hover:bg-[var(--vc-surface)]">
                            {columns.map((column) => (
                                <td
                                    key={column.key}
                                    className={[
                                        'border-b border-[var(--vc-divider)] px-3 py-3 align-top first:pl-0',
                                        column.numeric
                                            ? 'text-right vc-tabular whitespace-nowrap'
                                            : 'text-left',
                                    ].join(' ')}
                                >
                                    {column.render(row)}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

/** The em dash a table shows where no figure was ever captured. */
export function NoFigure() {
    return <span aria-label="not applicable">—</span>;
}
