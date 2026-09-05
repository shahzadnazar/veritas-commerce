import { Link, useForm, usePage } from '@inertiajs/react';
import { SellerLayout } from '../../layouts/SellerLayout';
import { Table, type Column } from '../../../design-system/patterns/Table';
import { Button } from '../../../design-system/primitives/Button';
import { StatusBadge } from '../../../design-system/primitives/StatusBadge';
import { FlashBanner } from '../../../design-system/patterns/States';
import type { PayoutAllocationView, PayoutDetailView } from '../../../shared/commerce';
import type { SharedPageProps } from '../../../shared/types';

interface PayoutShowProps extends SharedPageProps {
    payout: PayoutDetailView;
    can: {
        viewPayouts: boolean;
        requestPayout: boolean;
        manageDestination: boolean;
        minimum: string;
        minimumMinor: number;
        currency: string;
    };
}

/**
 * One payout, and everything that happened to it. §20.
 *
 * The history is shown in full, including a rejection reason, because a
 * seller whose withdrawal was refused should not have to email support to
 * find out why. What is not shown is anything about the provider or the
 * destination beyond the label the seller chose themselves.
 */
export default function PayoutShow() {
    const { payout, can, flash } = usePage<PayoutShowProps>().props;

    const allocationColumns: Column<PayoutAllocationView>[] = [
        {
            key: 'order',
            header: 'From',
            render: (row) =>
                row.orderReference ? (
                    <Link
                        href={`/seller/orders/${row.orderReference}`}
                        className="underline underline-offset-4"
                    >
                        {row.orderReference}
                    </Link>
                ) : (
                    'Adjustment'
                ),
        },
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

    return (
        <SellerLayout
            title={`Payout ${payout.reference}`}
            actions={
                <Link href="/seller/payouts" className="text-[13px] underline underline-offset-4">
                    All payouts
                </Link>
            }
        >
            <FlashBanner success={flash.success} error={flash.error} />

            <section className="mb-8">
                <div className="flex flex-wrap items-baseline gap-3">
                    <p className="text-[32px] tabular-nums">{payout.amount}</p>
                    <StatusBadge domain="payout" value={payout.status} />
                </div>

                <dl className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <Fact label="Requested" value={new Date(payout.requestedAt).toLocaleString()} />
                    <Fact label="Destination" value={payout.destinationLabel} />
                    <Fact
                        label="Sent"
                        value={payout.paidAt ? new Date(payout.paidAt).toLocaleString() : 'Not yet'}
                    />
                    <Fact label="Reference" value={payout.settlementReference ?? '—'} />
                </dl>

                {payout.decisionReason ? (
                    <p className="mt-3 max-w-[62ch] border-l-2 border-[var(--vc-accent)] bg-[var(--vc-accent-100)] p-3 text-[13px]">
                        {payout.decisionReason}
                    </p>
                ) : null}

                {payout.canCancel && can.requestPayout ? (
                    <CancelForm reference={payout.reference} />
                ) : null}
            </section>

            <section className="mb-8">
                <h2 className="mb-1 text-[20px]">What is funding this</h2>
                <p className="mb-3 max-w-[62ch] text-[13px] text-[var(--vc-neutral-700)]">
                    The earnings held for this payout. They stay held until it is sent or the
                    request is closed.
                </p>

                <Table
                    caption="Payout allocations"
                    columns={allocationColumns}
                    rows={payout.allocations}
                    rowKey={(row) => row.id}
                />
            </section>

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
                                — {new Date(entry.at).toLocaleString()}
                            </span>
                            {entry.reason ? (
                                <p className="mt-1 text-[var(--vc-neutral-700)]">{entry.reason}</p>
                            ) : null}
                        </li>
                    ))}
                </ol>
            </section>
        </SellerLayout>
    );
}

function Fact({ label, value }: { label: string; value: string }) {
    return (
        <div className="border-t-2 border-[var(--vc-text)] pt-2">
            <dt className="text-[11px] uppercase tracking-[0.08em] text-[var(--vc-neutral-700)]">
                {label}
            </dt>
            <dd className="mt-1">{value}</dd>
        </div>
    );
}

/**
 * Withdrawing the request, which is only offered while it is still
 * untouched. Once finance has picked it up the button is gone — and the
 * server refuses it too, which is the control (§26).
 */
function CancelForm({ reference }: { reference: string }) {
    const form = useForm({ reason: '' });

    return (
        <form
            className="mt-3"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(`/seller/payouts/${reference}/cancel`, { preserveScroll: true });
            }}
        >
            <Button type="submit" variant="secondary" disabled={form.processing}>
                {form.processing ? 'Cancelling…' : 'Cancel this request'}
            </Button>
            <p className="mt-1 text-[13px] text-[var(--vc-neutral-700)]">
                The money goes straight back into your available balance.
            </p>
        </form>
    );
}
