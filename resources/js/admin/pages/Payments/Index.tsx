import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { EmptyState } from '../../../design-system/patterns/States';
import { Table } from '../../../design-system/patterns/Table';
import type { Column } from '../../../design-system/patterns/Table';
import { Field, Input, Select } from '../../../design-system/primitives/Field';
import { StatusBadge } from '../../../design-system/primitives/StatusBadge';
import type { Paginated } from '../../../shared/commerce';
import type { SharedPageProps } from '../../../shared/types';
import { AdminLayout } from '../../layouts/AdminLayout';

interface PaymentRow {
    publicId: string;
    orderReference: string;
    provider: string;
    status: string;
    capturedAt: string | null;
    amount: string;
    amountMinor: number;
    refunded: string;
    refundedMinor: number;
    netMinor: number;
}

interface IndexProps extends SharedPageProps {
    payments: Paginated<PaymentRow>;
    filters: { reference?: string; status?: string; from?: string; to?: string };
    statuses: { value: string; label: string }[];
}

/**
 * Every captured payment, with what has been given back beside it.
 *
 * Captured and refunded are shown as separate columns rather than one net
 * figure, because they answer different questions and a single number
 * hides the one that matters: an order fully refunded and an order never
 * paid both net to nothing, and only one of them is a problem.
 */
export default function Index() {
    const { payments, filters, statuses } = usePage<IndexProps>().props;
    const [reference, setReference] = useState(filters.reference ?? '');

    const apply = (changes: Record<string, string>) => {
        router.get(
            '/admin/payments',
            { ...filters, reference, ...changes },
            { preserveState: true },
        );
    };

    const columns: Column<PaymentRow>[] = [
        {
            key: 'order',
            header: 'Order',
            render: (row) => (
                <Link
                    href={`/admin/payments/${row.orderReference}`}
                    className="font-semibold underline underline-offset-4"
                >
                    {row.orderReference}
                </Link>
            ),
        },
        {
            key: 'captured',
            header: 'Captured',
            render: (row) =>
                row.capturedAt ? (
                    <time dateTime={row.capturedAt}>
                        {new Date(row.capturedAt).toLocaleDateString()}
                    </time>
                ) : (
                    '—'
                ),
        },
        { key: 'amount', header: 'Captured', numeric: true, render: (row) => row.amount },
        {
            key: 'refunded',
            header: 'Refunded',
            numeric: true,
            render: (row) => (row.refundedMinor > 0 ? row.refunded : '—'),
        },
        {
            key: 'status',
            header: 'Status',
            render: (row) => <StatusBadge domain="payment" value={row.status} />,
        },
        { key: 'provider', header: 'Provider', render: (row) => row.provider },
    ];

    return (
        <AdminLayout title="Payments">
            <form
                className="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    apply({});
                }}
            >
                <Field label="Order number">
                    {({ id }) => (
                        <Input
                            id={id}
                            value={reference}
                            placeholder="VC-24081"
                            onChange={(event) => setReference(event.target.value)}
                        />
                    )}
                </Field>

                <Field label="Status">
                    {({ id }) => (
                        <Select
                            id={id}
                            value={filters.status ?? ''}
                            onChange={(event) => apply({ status: event.target.value })}
                        >
                            <option value="">Any status</option>
                            {statuses.map((status) => (
                                <option key={status.value} value={status.value}>
                                    {status.label}
                                </option>
                            ))}
                        </Select>
                    )}
                </Field>

                <Field label="Captured from">
                    {({ id }) => (
                        <Input
                            id={id}
                            type="date"
                            value={filters.from ?? ''}
                            onChange={(event) => apply({ from: event.target.value })}
                        />
                    )}
                </Field>

                <Field label="Captured to">
                    {({ id }) => (
                        <Input
                            id={id}
                            type="date"
                            value={filters.to ?? ''}
                            onChange={(event) => apply({ to: event.target.value })}
                        />
                    )}
                </Field>
            </form>

            <Table
                caption="Captured payments"
                columns={columns}
                rows={payments.data}
                rowKey={(row) => row.publicId}
                empty={<EmptyState title="No payments match" body="Adjust the filters above." />}
            />

            {payments.lastPage > 1 ? (
                <nav aria-label="Pagination" className="mt-6 flex gap-4 text-[13px]">
                    {payments.currentPage > 1 ? (
                        <Link href={`/admin/payments?page=${payments.currentPage - 1}`}>
                            Previous
                        </Link>
                    ) : null}
                    <span aria-current="page">
                        Page {payments.currentPage} of {payments.lastPage}
                    </span>
                    {payments.currentPage < payments.lastPage ? (
                        <Link href={`/admin/payments?page=${payments.currentPage + 1}`}>Next</Link>
                    ) : null}
                </nav>
            ) : null}
        </AdminLayout>
    );
}
