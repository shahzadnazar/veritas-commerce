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

interface OrderRow {
    reference: string;
    placedAt: string | null;
    status: string;
    email: string;
    customerName: string;
    sellerOrderCount: number;
    grandTotal: string;
    grandTotalMinor: number;
}

interface IndexProps extends SharedPageProps {
    orders: Paginated<OrderRow>;
    filters: { reference?: string; status?: string; seller?: string; from?: string; to?: string };
    statuses: { value: string; label: string }[];
}

/**
 * Every marketplace order.
 *
 * Filtered and paginated on the server, and the seller-order count is a
 * SQL count rather than a loaded relation: a list page that hydrated each
 * order's whole aggregate would read a large slice of the marketplace to
 * render twenty-five rows.
 */
export default function Index() {
    const { orders, filters, statuses } = usePage<IndexProps>().props;
    const [reference, setReference] = useState(filters.reference ?? '');
    const [seller, setSeller] = useState(filters.seller ?? '');

    const apply = (changes: Record<string, string>) => {
        router.get(
            '/admin/orders',
            { ...filters, reference, seller, ...changes },
            { preserveState: true },
        );
    };

    const columns: Column<OrderRow>[] = [
        {
            key: 'reference',
            header: 'Order',
            render: (row) => (
                <>
                    <Link
                        href={`/admin/orders/${row.reference}`}
                        className="font-semibold underline underline-offset-4"
                    >
                        {row.reference}
                    </Link>
                    <br />
                    <span className="text-[12px] text-[var(--vc-neutral-600)]">
                        {row.sellerOrderCount}{' '}
                        {row.sellerOrderCount === 1 ? 'seller order' : 'seller orders'}
                    </span>
                </>
            ),
        },
        {
            key: 'customer',
            header: 'Customer',
            render: (row) => (
                <>
                    {row.customerName}
                    <br />
                    <span className="text-[12px] text-[var(--vc-neutral-600)]">{row.email}</span>
                </>
            ),
        },
        {
            key: 'placed',
            header: 'Placed',
            render: (row) =>
                row.placedAt ? (
                    <time dateTime={row.placedAt}>
                        {new Date(row.placedAt).toLocaleDateString()}
                    </time>
                ) : (
                    '—'
                ),
        },
        { key: 'total', header: 'Total', numeric: true, render: (row) => row.grandTotal },
        {
            key: 'status',
            header: 'Status',
            render: (row) => <StatusBadge domain="marketplace_order" value={row.status} />,
        },
    ];

    return (
        <AdminLayout title="Orders">
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

                <Field label="Store">
                    {({ id }) => (
                        <Input
                            id={id}
                            value={seller}
                            onChange={(event) => setSeller(event.target.value)}
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

                <Field label="Placed from">
                    {({ id }) => (
                        <Input
                            id={id}
                            type="date"
                            value={filters.from ?? ''}
                            onChange={(event) => apply({ from: event.target.value })}
                        />
                    )}
                </Field>
            </form>

            <Table
                caption="Marketplace orders"
                columns={columns}
                rows={orders.data}
                rowKey={(row) => row.reference}
                empty={<EmptyState title="No orders match" body="Adjust the filters above." />}
            />

            {orders.lastPage > 1 ? (
                <nav aria-label="Pagination" className="mt-6 flex gap-4 text-[13px]">
                    {orders.currentPage > 1 ? (
                        <Link href={`/admin/orders?page=${orders.currentPage - 1}`}>Previous</Link>
                    ) : null}
                    <span aria-current="page">
                        Page {orders.currentPage} of {orders.lastPage}
                    </span>
                    {orders.currentPage < orders.lastPage ? (
                        <Link href={`/admin/orders?page=${orders.currentPage + 1}`}>Next</Link>
                    ) : null}
                </nav>
            ) : null}
        </AdminLayout>
    );
}
