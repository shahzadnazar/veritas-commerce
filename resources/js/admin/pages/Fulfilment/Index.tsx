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

interface FulfilmentRow {
    reference: string;
    parentReference: string;
    storeName: string;
    status: string;
    placedAt: string | null;
    shippedAt: string | null;
    deliveredAt: string | null;
    earningsClearAt: string | null;
    completedAt: string | null;
    orderTotal: string;
}

interface IndexProps extends SharedPageProps {
    orders: Paginated<FulfilmentRow>;
    filters: {
        reference?: string;
        store?: string;
        status?: string;
        carrier?: string;
        tracking?: string;
        clearing?: string;
        from?: string;
        to?: string;
    };
    statuses: { value: string; label: string }[];
}

/**
 * Every seller's fulfilment work, in one operational queue.
 *
 * Unpaid orders are excluded: fulfilment starts at payment, and listing
 * work nobody may do yet would only bury the work somebody must.
 *
 * The clearing filter is the one an operator reaches for when a seller
 * asks where their money is — "due" is everything the sweep should have
 * released, which is how a stuck order gets noticed.
 */
export default function Index() {
    const { orders, filters, statuses } = usePage<IndexProps>().props;
    const [reference, setReference] = useState(filters.reference ?? '');
    const [store, setStore] = useState(filters.store ?? '');
    const [tracking, setTracking] = useState(filters.tracking ?? '');

    const apply = (changes: Record<string, string>) => {
        router.get(
            '/admin/fulfilment',
            { ...filters, reference, store, tracking, ...changes },
            { preserveState: true },
        );
    };

    const columns: Column<FulfilmentRow>[] = [
        {
            key: 'reference',
            header: 'Seller order',
            render: (row) => (
                <>
                    <Link
                        href={`/admin/fulfilment/${row.reference}`}
                        className="font-semibold underline underline-offset-4"
                    >
                        {row.reference}
                    </Link>
                    <span className="block text-[12px] text-[var(--vc-neutral-600)]">
                        {row.parentReference}
                    </span>
                </>
            ),
        },
        { key: 'store', header: 'Store', render: (row) => row.storeName },
        {
            key: 'status',
            header: 'Fulfilment',
            render: (row) => <StatusBadge domain="seller_order" value={row.status} />,
        },
        {
            key: 'shipped',
            header: 'Sent',
            render: (row) =>
                row.shippedAt ? (
                    <time dateTime={row.shippedAt}>
                        {new Date(row.shippedAt).toLocaleDateString()}
                    </time>
                ) : (
                    '—'
                ),
        },
        {
            key: 'delivered',
            header: 'Delivered',
            render: (row) =>
                row.deliveredAt ? (
                    <time dateTime={row.deliveredAt}>
                        {new Date(row.deliveredAt).toLocaleDateString()}
                    </time>
                ) : (
                    '—'
                ),
        },
        {
            key: 'clearing',
            header: 'Earnings clear',
            render: (row) =>
                row.completedAt ? (
                    'Released'
                ) : row.earningsClearAt ? (
                    <time dateTime={row.earningsClearAt}>
                        {new Date(row.earningsClearAt).toLocaleDateString()}
                    </time>
                ) : (
                    '—'
                ),
        },
        { key: 'total', header: 'Value', numeric: true, render: (row) => row.orderTotal },
    ];

    return (
        <AdminLayout title="Fulfilment">
            <form
                className="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    apply({});
                }}
            >
                <Field label="Order number" hint="Seller order or the customer's.">
                    {({ id, describedBy }) => (
                        <Input
                            id={id}
                            aria-describedby={describedBy}
                            value={reference}
                            placeholder="VC-24081"
                            onChange={(event) => setReference(event.target.value)}
                        />
                    )}
                </Field>

                <Field label="Store">
                    {({ id }) => (
                        <Input
                            id={id}
                            value={store}
                            onChange={(event) => setStore(event.target.value)}
                        />
                    )}
                </Field>

                <Field label="Tracking number">
                    {({ id }) => (
                        <Input
                            id={id}
                            value={tracking}
                            onChange={(event) => setTracking(event.target.value)}
                        />
                    )}
                </Field>

                <Field label="Fulfilment state">
                    {({ id }) => (
                        <Select
                            id={id}
                            value={filters.status ?? ''}
                            onChange={(event) => apply({ status: event.target.value })}
                        >
                            <option value="">Any state</option>
                            {statuses.map((status) => (
                                <option key={status.value} value={status.value}>
                                    {status.label}
                                </option>
                            ))}
                        </Select>
                    )}
                </Field>

                <Field label="Earnings">
                    {({ id }) => (
                        <Select
                            id={id}
                            value={filters.clearing ?? ''}
                            onChange={(event) => apply({ clearing: event.target.value })}
                        >
                            <option value="">Any</option>
                            <option value="clearing">Still clearing</option>
                            <option value="due">Due for release</option>
                        </Select>
                    )}
                </Field>

                <Field label="Carrier">
                    {({ id }) => (
                        <Input
                            id={id}
                            defaultValue={filters.carrier ?? ''}
                            onBlur={(event) => apply({ carrier: event.target.value })}
                        />
                    )}
                </Field>
            </form>

            <Table
                caption="Seller fulfilment"
                columns={columns}
                rows={orders.data}
                rowKey={(row) => row.reference}
                empty={
                    <EmptyState title="No fulfilment matches" body="Adjust the filters above." />
                }
            />

            {orders.lastPage > 1 ? (
                <nav aria-label="Pagination" className="mt-6 flex gap-4 text-[13px]">
                    {orders.currentPage > 1 ? (
                        <Link href={`/admin/fulfilment?page=${orders.currentPage - 1}`}>
                            Previous
                        </Link>
                    ) : null}
                    <span aria-current="page">
                        Page {orders.currentPage} of {orders.lastPage}
                    </span>
                    {orders.currentPage < orders.lastPage ? (
                        <Link href={`/admin/fulfilment?page=${orders.currentPage + 1}`}>Next</Link>
                    ) : null}
                </nav>
            ) : null}
        </AdminLayout>
    );
}
