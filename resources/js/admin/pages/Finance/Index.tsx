import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { AdminLayout } from '../../layouts/AdminLayout';
import { Table, type Column } from '../../../design-system/patterns/Table';
import { Button } from '../../../design-system/primitives/Button';
import { Field, Input, Select } from '../../../design-system/primitives/Field';
import { EmptyState, FlashBanner } from '../../../design-system/patterns/States';
import type { NegativeSellerView, PlatformFinanceSummaryView } from '../../../shared/commerce';
import type { SharedPageProps } from '../../../shared/types';

interface FinanceProps extends SharedPageProps {
    summary: PlatformFinanceSummaryView;
    negativeSellers: NegativeSellerView[];
    filters: { from: string; to: string; currency: string; timezone: string };
    currencies: string[];
}

/**
 * Platform finance. §39.
 *
 * Two groups, because they are two different kinds of number and putting
 * them in one grid invites adding them together:
 *
 *   FLOWS     what moved in the window — GMV, refunds, commission,
 *             earnings, payouts.
 *   BALANCES  what is held right now. Deliberately NOT windowed: the
 *             ledger records when each entry was written, not what the
 *             balance was on a date, and "liability as of March" is a
 *             question this data cannot answer honestly.
 *
 * Every label is one of the definitions in SummarisePlatformFinance.
 * "Revenue" appears nowhere — it means whichever of four figures the
 * reader assumed.
 */
export default function Finance() {
    const { summary, negativeSellers, filters, currencies, flash } = usePage<FinanceProps>().props;

    const [draft, setDraft] = useState(filters);

    const columns: Column<NegativeSellerView>[] = [
        { key: 'seller', header: 'Store', render: (row) => row.sellerName },
        { key: 'net', header: 'Net balance', numeric: true, render: (row) => row.net },
        {
            key: 'incoming',
            header: 'Pending and clearing',
            numeric: true,
            render: (row) => row.incoming,
        },
    ];

    return (
        <AdminLayout title="Finance">
            <FlashBanner success={flash.success} error={flash.error} />

            <form
                className="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    router.get('/admin/finance', draft, { preserveState: true });
                }}
            >
                <Field label="From">
                    {({ id }) => (
                        <Input
                            id={id}
                            type="date"
                            value={draft.from}
                            onChange={(event) => setDraft({ ...draft, from: event.target.value })}
                        />
                    )}
                </Field>

                <Field label="To">
                    {({ id }) => (
                        <Input
                            id={id}
                            type="date"
                            value={draft.to}
                            onChange={(event) => setDraft({ ...draft, to: event.target.value })}
                        />
                    )}
                </Field>

                <Field label="Currency" hint={`Dates read in ${filters.timezone}.`}>
                    {({ id, describedBy }) => (
                        <Select
                            id={id}
                            aria-describedby={describedBy}
                            value={draft.currency}
                            onChange={(event) =>
                                setDraft({ ...draft, currency: event.target.value })
                            }
                        >
                            {currencies.map((currency) => (
                                <option key={currency} value={currency}>
                                    {currency}
                                </option>
                            ))}
                        </Select>
                    )}
                </Field>

                <div className="self-end">
                    <Button type="submit">Apply</Button>
                </div>
            </form>

            <section className="mb-10">
                <h2 className="mb-1 text-[20px]">Money that moved</h2>
                <p className="mb-3 max-w-[70ch] text-[13px] text-[var(--vc-neutral-700)]">
                    In {summary.currency}, over the window above. Commission comes from the
                    immutable order snapshots, not from the current rate, so a rate change never
                    moves a past figure.
                </p>

                <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <Figure
                        label="GMV"
                        value={summary.flows.gmv}
                        note="Captured payments, before refunds."
                    />
                    <Figure
                        label="Refunds"
                        value={summary.flows.refunds}
                        note="Successful refunds only."
                    />
                    <Figure
                        label="Net sales"
                        value={summary.flows.netSales}
                        note="GMV less refunds."
                    />
                    <Figure
                        label="Platform commission"
                        value={summary.flows.commission}
                        note="Recognised commission less reversals."
                    />
                    <Figure
                        label="Seller earnings"
                        value={summary.flows.sellerEarnings}
                        note="Credited to sellers, less refund reversals."
                    />
                    <Figure
                        label="Payouts sent"
                        value={summary.flows.payoutsPaid}
                        note="Actually settled, not merely approved."
                    />
                </dl>
            </section>

            <section className="mb-10">
                <h2 className="mb-1 text-[20px]">What the platform holds</h2>
                <p className="mb-3 max-w-[70ch] text-[13px] text-[var(--vc-neutral-700)]">
                    Balances as they stand now, not for the window — the ledger records when each
                    entry was written, not what a balance was on a past date.
                </p>

                <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <Figure
                        label="Pending"
                        value={summary.balances.pending}
                        note="Paid for, not delivered."
                    />
                    <Figure
                        label="Clearing"
                        value={summary.balances.clearing}
                        note="Delivered, inside the clearing period."
                    />
                    <Figure
                        label="Available"
                        value={summary.balances.available}
                        note="Cleared, net of payouts already sent."
                    />
                    <Figure
                        label="Reserved"
                        value={summary.balances.reserved}
                        note="Held by open payout requests."
                    />
                    <Figure
                        label="Open payouts"
                        value={summary.balances.openPayouts}
                        note="Requested and not yet decided or sent."
                    />
                    <Figure
                        label="Seller liability"
                        value={summary.balances.liability}
                        note="What the platform owes sellers in total."
                        emphasis
                    />
                </dl>
            </section>

            <section>
                <h2 className="mb-1 text-[20px]">Stores with a negative balance</h2>
                <p className="mb-3 max-w-[70ch] text-[13px] text-[var(--vc-neutral-700)]">
                    A refund landed against money that had already been paid out. Their next
                    earnings offset it; nothing is chased.
                </p>

                <Table
                    caption="Negative seller balances"
                    columns={columns}
                    rows={negativeSellers}
                    rowKey={(row) => row.sellerAccountId}
                    empty={
                        <EmptyState
                            title="Every store is square"
                            body="No seller is carrying a negative balance."
                        />
                    }
                />
            </section>
        </AdminLayout>
    );
}

function Figure({
    label,
    value,
    note,
    emphasis = false,
}: {
    label: string;
    value: string;
    note: string;
    emphasis?: boolean;
}) {
    return (
        <div
            className={`border-t-2 pt-2 ${
                emphasis ? 'border-[var(--vc-accent)]' : 'border-[var(--vc-text)]'
            }`}
        >
            <dt className="text-[11px] uppercase tracking-[0.08em] text-[var(--vc-neutral-700)]">
                {label}
            </dt>
            <dd className="mt-1 text-[24px] tabular-nums">{value}</dd>
            <dd className="mt-1 text-[13px] text-[var(--vc-neutral-700)]">{note}</dd>
        </div>
    );
}
