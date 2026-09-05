import { Link, useForm, usePage } from '@inertiajs/react';
import { AdminLayout } from '../../layouts/AdminLayout';
import { Table, type Column } from '../../../design-system/patterns/Table';
import { Button } from '../../../design-system/primitives/Button';
import { Field, Input, Textarea } from '../../../design-system/primitives/Field';
import { StatusBadge } from '../../../design-system/primitives/StatusBadge';
import { FlashBanner } from '../../../design-system/patterns/States';
import type {
    PayoutAllocationView,
    PayoutDetailView,
    PayoutSettlementAttemptView,
    SellerFinancialPositionView,
} from '../../../shared/commerce';
import type { SharedPageProps } from '../../../shared/types';

interface PayoutDetailProps extends SharedPageProps {
    payout: PayoutDetailView;
    seller: {
        id: string;
        name: string;
        status: string;
        statusLabel: string;
    } | null;
    position: SellerFinancialPositionView;
    withdrawableAfter: string;
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
 * One payout, everything about it, and the four things finance can do. §22.
 *
 * The actions offered are the ones the state machine allows AND this
 * admin's permissions include. Both halves matter and neither is the
 * control: the server checks the permission on the route and the state
 * inside the action, so hiding a button here is a courtesy (§47).
 *
 * "Approve" is worded carefully everywhere it appears. Approving
 * authorises a transfer; it does not make one, and a seller has not been
 * paid until somebody records that they were.
 */
export default function PayoutDetail() {
    const { payout, seller, position, withdrawableAfter, can, flash } =
        usePage<PayoutDetailProps>().props;

    const allocationColumns: Column<PayoutAllocationView>[] = [
        { key: 'order', header: 'From order', render: (row) => row.orderReference ?? 'Adjustment' },
        {
            key: 'earned',
            header: 'Earned',
            render: (row) => new Date(row.earnedAt).toLocaleDateString(),
        },
        { key: 'amount', header: 'Amount', numeric: true, render: (row) => row.amount },
        {
            key: 'status',
            header: 'State',
            render: (row) => <StatusBadge domain="payout_allocation" value={row.status} />,
        },
    ];

    const attemptColumns: Column<PayoutSettlementAttemptView>[] = [
        {
            key: 'initiated',
            header: 'Attempted',
            render: (row) => new Date(row.initiatedAt).toLocaleString(),
        },
        { key: 'method', header: 'Method', render: (row) => row.method ?? '—' },
        { key: 'reference', header: 'Reference', render: (row) => row.reference ?? '—' },
        { key: 'amount', header: 'Amount', numeric: true, render: (row) => row.amount },
        {
            key: 'status',
            header: 'Result',
            render: (row) => (
                <span>
                    <StatusBadge domain="payout_settlement_attempt" value={row.status} />
                    {row.failureMessage ? (
                        <span className="mt-1 block text-[12px] text-[var(--vc-neutral-700)]">
                            {row.failureMessage}
                        </span>
                    ) : null}
                </span>
            ),
        },
    ];

    return (
        <AdminLayout title={`Payout ${payout.reference}`}>
            <FlashBanner success={flash.success} error={flash.error} />

            <div className="mb-8 flex flex-wrap items-baseline gap-3">
                <p className="text-[32px] tabular-nums">{payout.amount}</p>
                <StatusBadge domain="payout" value={payout.status} />
                {seller !== null ? (
                    <Link
                        href={`/admin/sellers/${seller.id}/finance`}
                        className="text-[13px] underline underline-offset-4"
                    >
                        {seller.name}
                    </Link>
                ) : null}
                {seller !== null ? <StatusBadge domain="seller" value={seller.status} /> : null}
            </div>

            <section className="mb-8">
                <h2 className="mb-3 text-[20px]">Store finance</h2>
                <dl className="grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    <Fact label="Pending" value={position.pending} />
                    <Fact label="Clearing" value={position.clearing} />
                    <Fact label="Available" value={position.available} />
                    <Fact label="Reserved" value={position.reserved} />
                    <Fact label="Withdrawable" value={position.withdrawable} />
                    <Fact label="After this payout" value={withdrawableAfter} />
                </dl>
                {position.isNegative ? (
                    <p className="mt-3 border-l-2 border-[var(--vc-accent)] bg-[var(--vc-accent-100)] p-3 text-[13px]">
                        This store's net balance is below zero. A refund has landed against money
                        that was already paid out.
                    </p>
                ) : null}
            </section>

            <section className="mb-8">
                <h2 className="mb-3 text-[20px]">Request</h2>
                <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <Fact label="Requested" value={new Date(payout.requestedAt).toLocaleString()} />
                    <Fact
                        label="Reviewed"
                        value={
                            payout.reviewedAt ? new Date(payout.reviewedAt).toLocaleString() : '—'
                        }
                    />
                    <Fact
                        label="Approved"
                        value={
                            payout.approvedAt ? new Date(payout.approvedAt).toLocaleString() : '—'
                        }
                    />
                    <Fact
                        label="Sent"
                        value={payout.paidAt ? new Date(payout.paidAt).toLocaleString() : 'Not yet'}
                    />
                    <Fact label="Destination" value={payout.destinationLabel} />
                    <Fact label="Settlement method" value={payout.settlementMethod ?? '—'} />
                    <Fact label="Settlement reference" value={payout.settlementReference ?? '—'} />
                    <Fact
                        label="Ledger debit"
                        value={payout.ledgerDebit ? payout.ledgerDebit.amount : 'None yet'}
                    />
                </dl>

                {can.viewSensitive && payout.destination ? (
                    <div className="mt-3 border-l-2 border-[var(--vc-text)] p-3">
                        <h3 className="text-[13px] font-semibold">Destination detail</h3>
                        <p className="text-[13px] text-[var(--vc-neutral-700)]">
                            {payout.destination.type} via {payout.destination.provider}
                            {payout.destination.last4 ? ` ····${payout.destination.last4}` : ''}
                            {payout.destination.country ? `, ${payout.destination.country}` : ''}
                        </p>
                        {payout.destination.changedAt ? (
                            <p className="mt-1 text-[13px] text-[var(--vc-accent-800)]">
                                This destination was changed on{' '}
                                {new Date(payout.destination.changedAt).toLocaleDateString()}.
                            </p>
                        ) : null}
                    </div>
                ) : null}
            </section>

            <section className="mb-8">
                <h2 className="mb-3 text-[20px]">Decisions</h2>
                <div className="grid gap-6 lg:grid-cols-2">
                    {can.review && payout.status === 'requested' ? (
                        <SimpleAction
                            action={`/admin/payouts/${payout.reference}/review`}
                            label="Start review"
                            body="Records that you have picked this up."
                        />
                    ) : null}

                    {can.approve &&
                    (payout.status === 'requested' || payout.status === 'under_review') ? (
                        <NoteAction
                            action={`/admin/payouts/${payout.reference}/approve`}
                            label="Approve"
                            body="Authorises this payout for settlement. It does NOT send money and the seller is told so."
                            field="note"
                            fieldLabel="Note (optional)"
                            required={false}
                        />
                    ) : null}

                    {can.reject && payout.isOpen ? (
                        <NoteAction
                            action={`/admin/payouts/${payout.reference}/reject`}
                            label="Reject"
                            body="Releases the reservation and returns the money to the store. The reason is shown to the seller."
                            field="reason"
                            fieldLabel="Reason"
                            required
                        />
                    ) : null}

                    {can.settle &&
                    (payout.status === 'approved' || payout.status === 'processing') ? (
                        <SettleForm reference={payout.reference} amount={payout.amount} />
                    ) : null}

                    {can.settle &&
                    (payout.status === 'approved' || payout.status === 'processing') ? (
                        <NoteAction
                            action={`/admin/payouts/${payout.reference}/fail`}
                            label="Record a failure"
                            body="Keeps the money reserved. Reject or cancel the request to release it."
                            field="reason"
                            fieldLabel="What went wrong"
                            required
                        />
                    ) : null}

                    {can.reject && payout.status === 'failed' ? (
                        <NoteAction
                            action={`/admin/payouts/${payout.reference}/cancel`}
                            label="Cancel and release"
                            body="Ends the request and returns the reserved money to the store."
                            field="reason"
                            fieldLabel="Reason"
                            required
                        />
                    ) : null}
                </div>
            </section>

            <section className="mb-8">
                <h2 className="mb-3 text-[20px]">What is funding this</h2>
                <Table
                    caption="Payout allocations"
                    columns={allocationColumns}
                    rows={payout.allocations}
                    rowKey={(row) => row.id}
                />
            </section>

            {payout.settlementAttempts.length > 0 ? (
                <section className="mb-8">
                    <h2 className="mb-3 text-[20px]">Settlement attempts</h2>
                    <Table
                        caption="Settlement attempts"
                        columns={attemptColumns}
                        rows={payout.settlementAttempts}
                        rowKey={(row) => row.id}
                    />
                </section>
            ) : null}

            <section>
                <h2 className="mb-3 text-[20px]">History</h2>
                <ol className="grid gap-2">
                    {payout.history.map((entry, index) => (
                        <li
                            key={`${entry.to}-${entry.at}-${index}`}
                            className="border-t border-[var(--vc-divider)] pt-2 text-[13px]"
                        >
                            <span className="font-semibold">{entry.toLabel}</span>
                            <span className="text-[var(--vc-neutral-700)]">
                                {' '}
                                — {entry.actorLabel ?? entry.actorType ?? 'system'},{' '}
                                {new Date(entry.at).toLocaleString()}
                            </span>
                            {entry.reason ? (
                                <p className="mt-1 text-[var(--vc-neutral-700)]">{entry.reason}</p>
                            ) : null}
                        </li>
                    ))}
                </ol>
            </section>
        </AdminLayout>
    );
}

function Fact({ label, value }: { label: string; value: string }) {
    return (
        <div className="border-t-2 border-[var(--vc-text)] pt-2">
            <dt className="text-[11px] uppercase tracking-[0.08em] text-[var(--vc-neutral-700)]">
                {label}
            </dt>
            <dd className="mt-1 tabular-nums">{value}</dd>
        </div>
    );
}

function SimpleAction({ action, label, body }: { action: string; label: string; body: string }) {
    const form = useForm({});

    return (
        <div className="border-2 border-[var(--vc-divider)] p-4">
            <h3 className="text-[16px]">{label}</h3>
            <p className="mb-3 text-[13px] text-[var(--vc-neutral-700)]">{body}</p>
            <Button
                type="button"
                disabled={form.processing}
                onClick={() => form.post(action, { preserveScroll: true })}
            >
                {label}
            </Button>
        </div>
    );
}

function NoteAction({
    action,
    label,
    body,
    field,
    fieldLabel,
    required,
}: {
    action: string;
    label: string;
    body: string;
    field: 'note' | 'reason';
    fieldLabel: string;
    required: boolean;
}) {
    const form = useForm<Record<string, string>>({ [field]: '' });

    return (
        <form
            className="border-2 border-[var(--vc-divider)] p-4"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(action, { preserveScroll: true });
            }}
        >
            <h3 className="text-[16px]">{label}</h3>
            <p className="mb-3 text-[13px] text-[var(--vc-neutral-700)]">{body}</p>

            <Field label={fieldLabel} error={form.errors[field]}>
                {({ id, describedBy, invalid }) => (
                    <Textarea
                        id={id}
                        aria-describedby={describedBy}
                        rows={2}
                        required={required}
                        value={form.data[field]}
                        invalid={invalid}
                        onChange={(event) => form.setData(field, event.target.value)}
                    />
                )}
            </Field>

            <div className="mt-3">
                <Button type="submit" disabled={form.processing}>
                    {label}
                </Button>
            </div>
        </form>
    );
}

/**
 * Recording that money left.
 *
 * The reference is required and the server refuses without it: it is the
 * only link between this record and a line on a bank statement, and a
 * settlement nobody can reconcile is a settlement nobody can audit (§27).
 */
function SettleForm({ reference, amount }: { reference: string; amount: string }) {
    const form = useForm({ method: 'wire', reference: '', note: '' });

    return (
        <form
            className="border-2 border-[var(--vc-text)] p-4"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(`/admin/payouts/${reference}/settle`, { preserveScroll: true });
            }}
        >
            <h3 className="text-[16px]">Record settlement</h3>
            <p className="mb-3 text-[13px] text-[var(--vc-neutral-700)]">
                Only after {amount} has actually been sent. This posts the payout debit to the
                seller's ledger and cannot be undone.
            </p>

            <div className="grid gap-3">
                <Field label="Method" error={form.errors.method}>
                    {({ id, describedBy, invalid }) => (
                        <Input
                            id={id}
                            aria-describedby={describedBy}
                            value={form.data.method}
                            invalid={invalid}
                            onChange={(event) => form.setData('method', event.target.value)}
                        />
                    )}
                </Field>

                <Field
                    label="Transfer reference"
                    hint="What this can be found by at the bank."
                    error={form.errors.reference}
                >
                    {({ id, describedBy, invalid }) => (
                        <Input
                            id={id}
                            aria-describedby={describedBy}
                            required
                            value={form.data.reference}
                            invalid={invalid}
                            onChange={(event) => form.setData('reference', event.target.value)}
                        />
                    )}
                </Field>

                <Field label="Note (optional)" error={form.errors.note}>
                    {({ id, describedBy, invalid }) => (
                        <Textarea
                            id={id}
                            aria-describedby={describedBy}
                            rows={2}
                            value={form.data.note}
                            invalid={invalid}
                            onChange={(event) => form.setData('note', event.target.value)}
                        />
                    )}
                </Field>
            </div>

            <div className="mt-3">
                <Button type="submit" variant="primary" disabled={form.processing}>
                    {form.processing ? 'Recording…' : 'Record settlement'}
                </Button>
            </div>
        </form>
    );
}
