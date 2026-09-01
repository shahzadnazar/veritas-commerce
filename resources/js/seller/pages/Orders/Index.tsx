import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { EmptyState } from '../../../design-system/patterns/States';
import { Table } from '../../../design-system/patterns/Table';
import type { Column } from '../../../design-system/patterns/Table';
import { Field, Input, Select } from '../../../design-system/primitives/Field';
import { StatusBadge } from '../../../design-system/primitives/StatusBadge';
import type { Paginated } from '../../../shared/commerce';
import type { SharedPageProps } from '../../../shared/types';
import { SellerLayout } from '../../layouts/SellerLayout';

interface OrderRow {
    reference: string;
    parentReference: string | null;
    placedAt: string | null;
    status: string;
    lineCount: number;
    unitCount: number;
    orderTotal: string;
    orderTotalMinor: number;
}

interface IndexProps extends SharedPageProps {
    orders: Paginated<OrderRow>;
    filters: { reference?: string; parent?: string; status?: string; from?: string; to?: string };
    statuses: { value: string; label: string }[];
}

/**
 * This seller's half of the marketplace's orders.
 *
 * Every row here belongs to the signed-in seller — enforced by the model's
 * tenant scope, not by this page's query. The parent reference is shown
 * because a customer quotes it when they get in touch; nothing else about
 * the parent, and nothing at all about the other sellers on it, crosses
 * over.
 *
 * Filtering happens on the server. A page that fetched everything and
 * filtered in the browser would be shipping the whole order book to a
 * laptop to hide most of it.
 */
export default function Index() {
    const { orders, filters, statuses } = usePage<IndexProps>().props;
    const [reference, setReference] = useState(filters.reference ?? '');
    const [parent, setParent] = useState(filters.parent ?? '');

    const apply = (changes: Record<string, string>) => {
        router.get(
            '/seller/orders',
            { ...filters, reference, parent, ...changes },
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
                        href={`/seller/orders/${row.reference}`}
                        className="font-semibold underline underline-offset-4"
                    >
                        {row.reference}
                    </Link>
                    <br />
                    <span className="text-[12px] text-[var(--vc-neutral-600)]">
                        Customer order {row.parentReference ?? '—'}
                    </span>
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
        {
            key: 'items',
            header: 'Items',
            numeric: true,
            render: (row) => `${row.lineCount} (${row.unitCount})`,
        },
        {
            key: 'total',
            header: 'Subtotal',
            numeric: true,
            render: (row) => row.orderTotal,
        },
        {
            key: 'status',
            header: 'Status',
            render: (row) => <StatusBadge domain="seller_order" value={row.status} />,
        },
    ];

    return (
        <SellerLayout title="Orders">
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
                            placeholder="VC-24081-01"
                            onChange={(event) => setReference(event.target.value)}
                        />
                    )}
                </Field>

                <Field label="Customer order number">
                    {({ id }) => (
                        <Input
                            id={id}
                            value={parent}
                            placeholder="VC-24081"
                            onChange={(event) => setParent(event.target.value)}
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
                caption="Your orders"
                columns={columns}
                rows={orders.data}
                rowKey={(row) => row.reference}
                empty={
                    <EmptyState
                        title="No orders yet"
                        body="Orders appear here as customers buy your listings. Each one is your part of a customer's order."
                    />
                }
            />

            {orders.lastPage > 1 ? (
                <nav aria-label="Pagination" className="mt-6 flex gap-4 text-[13px]">
                    {orders.currentPage > 1 ? (
                        <Link href={`/seller/orders?page=${orders.currentPage - 1}`}>Previous</Link>
                    ) : null}
                    <span aria-current="page">
                        Page {orders.currentPage} of {orders.lastPage}
                    </span>
                    {orders.currentPage < orders.lastPage ? (
                        <Link href={`/seller/orders?page=${orders.currentPage + 1}`}>Next</Link>
                    ) : null}
                </nav>
            ) : null}
        </SellerLayout>
    );
}
