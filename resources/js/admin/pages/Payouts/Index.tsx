import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { AdminLayout } from '../../layouts/AdminLayout';
import { Table, type Column } from '../../../design-system/patterns/Table';
import { Button } from '../../../design-system/primitives/Button';
import { Field, Input, Select } from '../../../design-system/primitives/Field';
import { StatusBadge } from '../../../design-system/primitives/StatusBadge';
import { EmptyState, FlashBanner } from '../../../design-system/patterns/States';
import type { AdminPayoutSummaryView } from '../../../shared/commerce';
import type { SharedPageProps } from '../../../shared/types';

interface PayoutQueueProps extends SharedPageProps {
    payouts: AdminPayoutSummaryView[];
    pagination: { page: number; lastPage: number; total: number };
    filters: {
        status: string;
        seller: string;
        currency: string;
        from: string;
        to: string;
    };
    statuses: { value: string; label: string }[];
    currencies: string[];
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
 * The payout queue. §21.
 *
 * Every row carries the seller's withdrawable balance beside the amount
 * they asked for, because the first question a reviewer has is whether the
 * store can actually fund this — and a queue that made them open each
 * record to find out is a queue nobody checks properly. It is one grouped
 * query for the whole page, not one per row.
 */
export default function PayoutQueue() {
    const { payouts, pagination, filters, statuses, currencies, flash } =
        usePage<PayoutQueueProps>().props;

    const [draft, setDraft] = useState(filters);

    const columns: Column<AdminPayoutSummaryView>[] = [
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
        { key: 'seller', header: 'Store', render: (row) => row.sellerName },
        {
            key: 'requested',
            header: 'Requested',
            render: (row) => new Date(row.requestedAt).toLocaleDateString(),
        },
        { key: 'amount', header: 'Amount', numeric: true, render: (row) => row.amount },
        {
            key: 'withdrawable',
            header: 'Store balance',
            numeric: true,
            render: (row) => (
                <span className={row.sellerIsNegative ? 'text-[var(--vc-accent-800)]' : undefined}>
                    {row.sellerWithdrawable ?? '—'}
                </span>
            ),
        },
        { key: 'currency', header: 'Currency', render: (row) => row.currency },
        {
            key: 'status',
            header: 'Status',
            render: (row) => <StatusBadge domain="payout" value={row.status} />,
        },
    ];

    return (
        <AdminLayout title="Payouts">
            <FlashBanner success={flash.success} error={flash.error} />

            <form
                className="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-5"
                onSubmit={(event) => {
                    event.preventDefault();
                    router.get('/admin/payouts', draft, { preserveState: true });
                }}
            >
                <Field label="Status">
                    {({ id }) => (
                        <Select
                            id={id}
                            value={draft.status}
                            onChange={(event) => setDraft({ ...draft, status: event.target.value })}
                        >
                            <option value="">Any status</option>
                            <option value="open">Open (holding money)</option>
                            {statuses.map((status) => (
                                <option key={status.value} value={status.value}>
                                    {status.label}
                                </option>
                            ))}
                        </Select>
                    )}
                </Field>

                <Field label="Store">
                    {({ id }) => (
                        <Input
                            id={id}
                            value={draft.seller}
                            placeholder="Name or reference"
                            onChange={(event) => setDraft({ ...draft, seller: event.target.value })}
                        />
                    )}
                </Field>

                <Field label="Currency">
                    {({ id }) => (
                        <Select
                            id={id}
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

                <Field label="Requested from">
                    {({ id }) => (
                        <Input
                            id={id}
                            type="date"
                            value={draft.from}
                            onChange={(event) => setDraft({ ...draft, from: event.target.value })}
                        />
                    )}
                </Field>

                <Field label="Requested to">
                    {({ id }) => (
                        <Input
                            id={id}
                            type="date"
                            value={draft.to}
                            onChange={(event) => setDraft({ ...draft, to: event.target.value })}
                        />
                    )}
                </Field>

                <div className="sm:col-span-2 lg:col-span-5">
                    <Button type="submit">Filter</Button>
                </div>
            </form>

            <Table
                caption="Payout requests"
                columns={columns}
                rows={payouts}
                rowKey={(row) => row.id}
                empty={
                    <EmptyState
                        title="Nothing in the queue"
                        body="No payout request matches these filters."
                    />
                }
            />

            {pagination.lastPage > 1 ? (
                <nav className="mt-3 flex items-center gap-3 text-[13px]" aria-label="Payout pages">
                    <span className="text-[var(--vc-neutral-700)]">
                        Page {pagination.page} of {pagination.lastPage} — {pagination.total}{' '}
                        requests
                    </span>
                </nav>
            ) : null}
        </AdminLayout>
    );
}
