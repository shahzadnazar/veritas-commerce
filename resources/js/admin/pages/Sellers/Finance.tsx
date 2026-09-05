import { Link, useForm, usePage } from '@inertiajs/react';
import { AdminLayout } from '../../layouts/AdminLayout';
import { Table, type Column } from '../../../design-system/patterns/Table';
import { Button } from '../../../design-system/primitives/Button';
import { Field, Input, Textarea } from '../../../design-system/primitives/Field';
import { StatusBadge } from '../../../design-system/primitives/StatusBadge';
import { EmptyState, FlashBanner } from '../../../design-system/patterns/States';
import type {
    PayoutSummaryView,
    SellerFinancialPositionView,
    SellerLedgerRowView,
    SellerStatementView,
} from '../../../shared/commerce';
import type { SharedPageProps } from '../../../shared/types';

interface SellerFinanceProps extends SharedPageProps {
    seller: { id: string; name: string; status: string; statusLabel: string };
    position: SellerFinancialPositionView;
    statement: SellerStatementView;
    payouts: PayoutSummaryView[];
    currency: string;
    can: {
        review: boolean;
        approve: boolean;
        reject: boolean;
        settle: boolean;
        viewSensitive: boolean;
        adjust: boolean;
    };
}

/**
 * One store's whole financial picture. §72.
 *
 * The same statement the seller sees, from the same query, so support and
 * the seller are looking at one set of numbers rather than two that might
 * disagree. §19: a suspended store's history is shown in full — suspension
 * stops new withdrawals, it does not hide what happened.
 */
export default function SellerFinance() {
    const { seller, position, statement, payouts, currency, can, flash } =
        usePage<SellerFinanceProps>().props;

    const ledgerColumns: Column<SellerLedgerRowView>[] = [
        {
            key: 'date',
            header: 'Date',
            render: (row) => new Date(row.occurredAt).toLocaleDateString(),
        },
        { key: 'description', header: 'Description', render: (row) => row.description },
        { key: 'credit', header: 'In', numeric: true, render: (row) => row.credit ?? '—' },
        { key: 'debit', header: 'Out', numeric: true, render: (row) => row.debit ?? '—' },
        { key: 'balance', header: 'Balance', numeric: true, render: (row) => row.balanceAfter },
        {
            key: 'status',
            header: 'State',
            render: (row) => <StatusBadge domain="ledger_entry_status" value={row.status} />,
        },
    ];

    const payoutColumns: Column<PayoutSummaryView>[] = [
        {
            key: 'reference',
            header: 'Payout',
            render: (row) => (
                <Link
                    href={`/admin/payouts/${row.reference}`}
                    className="underline underline-offset-4"
                >
                    {row.reference}
                </Link>
            ),
        },
        {
            key: 'requested',
            header: 'Requested',
            render: (row) => new Date(row.requestedAt).toLocaleDateString(),
        },
        { key: 'amount', header: 'Amount', numeric: true, render: (row) => row.amount },
        {
            key: 'status',
            header: 'Status',
            render: (row) => <StatusBadge domain="payout" value={row.status} />,
        },
    ];

    return (
        <AdminLayout title={`${seller.name} — finance`}>
            <FlashBanner success={flash.success} error={flash.error} />

            <div className="mb-6 flex flex-wrap items-center gap-3">
                <StatusBadge domain="seller" value={seller.status} />
                <span className="text-[13px] text-[var(--vc-neutral-700)]">
                    All figures in {currency}.
                </span>
            </div>

            <section className="mb-10">
                <h2 className="mb-3 text-[20px]">Position</h2>
                <dl className="grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    <Figure label="Pending" value={position.pending} />
                    <Figure label="Clearing" value={position.clearing} />
                    <Figure label="Available" value={position.available} />
                    <Figure label="Reserved" value={position.reserved} />
                    <Figure label="Paid out" value={position.paidOut} />
                    <Figure label="Withdrawable" value={position.withdrawable} emphasis />
                </dl>

                {position.isNegative ? (
                    <p className="mt-3 border-l-2 border-[var(--vc-accent)] bg-[var(--vc-accent-100)] p-3 text-[13px]">
                        Net balance is {position.netBalance}. A refund landed against money already
                        paid out; future earnings will offset it.
                    </p>
                ) : null}
            </section>

            {can.adjust ? <AdjustmentForm sellerId={seller.id} currency={currency} /> : null}

            <section className="mb-10">
                <h2 className="mb-3 text-[20px]">Payouts</h2>
                <Table
                    caption="Payout history"
                    columns={payoutColumns}
                    rows={payouts}
                    rowKey={(row) => row.id}
                    empty={<EmptyState title="No payouts" body="This store has never withdrawn." />}
                />
            </section>

            <section>
                <h2 className="mb-3 text-[20px]">Statement</h2>
                <Table
                    caption="Seller ledger"
                    columns={ledgerColumns}
                    rows={statement.rows}
                    rowKey={(row) => row.id}
                    empty={<EmptyState title="No entries" body="This store has no ledger yet." />}
                />
            </section>
        </AdminLayout>
    );
}

function Figure({
    label,
    value,
    emphasis = false,
}: {
    label: string;
    value: string;
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
            <dd className="mt-1 text-[20px] tabular-nums">{value}</dd>
        </div>
    );
}

/**
 * A correction, which is exceptional and always audited. §64.
 *
 * Signed cents: positive credits the store, negative debits it. A credit
 * lands in clearing and waits like any other earning, so an adjustment
 * cannot be used to route money past the clearing window; a debit lands
 * available and bites at once.
 */
function AdjustmentForm({ sellerId, currency }: { sellerId: string; currency: string }) {
    const form = useForm({ amount_minor: 0, reason: '' });

    return (
        <section className="mb-10">
            <h2 className="mb-1 text-[20px]">Post an adjustment</h2>
            <p className="mb-3 max-w-[70ch] text-[13px] text-[var(--vc-neutral-700)]">
                Appends an immutable entry to this store's ledger in {currency}. Nothing existing is
                edited. A credit waits out the clearing period; a debit applies immediately.
            </p>

            <form
                className="grid max-w-[520px] gap-3"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(`/admin/sellers/${sellerId}/finance/adjust`, {
                        preserveScroll: true,
                        onSuccess: () => form.reset(),
                    });
                }}
            >
                <Field
                    label="Amount in cents"
                    hint="Positive credits the store; negative debits it."
                    error={form.errors.amount_minor}
                >
                    {({ id, describedBy, invalid }) => (
                        <Input
                            id={id}
                            aria-describedby={describedBy}
                            type="number"
                            step={1}
                            value={form.data.amount_minor}
                            invalid={invalid}
                            onChange={(event) =>
                                form.setData('amount_minor', Number(event.target.value))
                            }
                        />
                    )}
                </Field>

                <Field label="Reason" error={form.errors.reason}>
                    {({ id, describedBy, invalid }) => (
                        <Textarea
                            id={id}
                            aria-describedby={describedBy}
                            rows={2}
                            required
                            value={form.data.reason}
                            invalid={invalid}
                            onChange={(event) => form.setData('reason', event.target.value)}
                        />
                    )}
                </Field>

                <div>
                    <Button type="submit" disabled={form.processing}>
                        {form.processing ? 'Posting…' : 'Post adjustment'}
                    </Button>
                </div>
            </form>
        </section>
    );
}
