import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { AdminLayout } from '../../layouts/AdminLayout';
import { Field, Input, Select } from '../../../design-system/primitives/Field';
import { StatusBadge } from '../../../design-system/primitives/StatusBadge';
import { EmptyState, FlashBanner } from '../../../design-system/patterns/States';
import { Table } from '../../../design-system/patterns/Table';
import type { Column } from '../../../design-system/patterns/Table';
import type { SharedPageProps } from '../../../shared/types';

interface QueueRow {
    publicId: string;
    title: string;
    status: string;
    brand: string | null;
    category: string | null;
    proposedBy: string | null;
    submittedAt: string | null;
}

interface ProductsProps extends SharedPageProps {
    products: { data: QueueRow[]; currentPage: number; lastPage: number; total: number };
    filters: { status: string; search: string; category: string };
    statuses: { value: string; label: string }[];
    categories: { id: number; name: string }[];
}

/**
 * The moderation queue.
 *
 * Defaults to what is waiting on the team: a queue that opens on ten
 * thousand published products is a list, not a queue.
 */
export default function Products() {
    const { products, filters, statuses, categories, flash } = usePage<ProductsProps>().props;
    const [search, setSearch] = useState(filters.search);

    const apply = (changes: Record<string, string>) => {
        router.get(
            '/admin/catalogue/products',
            { ...filters, search, ...changes },
            { preserveState: true },
        );
    };

    const columns: Column<QueueRow>[] = [
        {
            key: 'product',
            header: 'Product',
            render: (row) => (
                <>
                    <Link
                        href={`/admin/catalogue/products/${row.publicId}`}
                        className="font-semibold underline underline-offset-4"
                    >
                        {row.title}
                    </Link>
                    <br />
                    <span className="text-[12px] text-[var(--vc-neutral-600)]">
                        {[row.brand, row.category].filter(Boolean).join(' · ') || '—'}
                    </span>
                </>
            ),
        },
        {
            key: 'seller',
            header: 'Proposed by',
            render: (row) => row.proposedBy ?? 'The marketplace',
        },
        { key: 'submitted', header: 'Waiting since', render: (row) => row.submittedAt ?? '—' },
        {
            key: 'status',
            header: 'Status',
            render: (row) => <StatusBadge domain="product" value={row.status} />,
        },
    ];

    return (
        <AdminLayout title="Catalogue queue">
            <FlashBanner success={flash.success} error={flash.error} />

            <form
                className="mb-6 flex flex-wrap items-end gap-3"
                onSubmit={(event) => {
                    event.preventDefault();
                    apply({});
                }}
            >
                <div className="min-w-[240px]">
                    <Field label="Search title or barcode">
                        {({ id }) => (
                            <Input
                                id={id}
                                value={search}
                                onChange={(event) => setSearch(event.target.value)}
                            />
                        )}
                    </Field>
                </div>

                <div className="min-w-[180px]">
                    <Field label="Status">
                        {({ id }) => (
                            <Select
                                id={id}
                                value={filters.status}
                                onChange={(event) => apply({ status: event.target.value })}
                            >
                                <option value="">Waiting on us</option>
                                <option value="all">Everything</option>
                                {statuses.map((status) => (
                                    <option key={status.value} value={status.value}>
                                        {status.label}
                                    </option>
                                ))}
                            </Select>
                        )}
                    </Field>
                </div>

                <div className="min-w-[200px]">
                    <Field label="Category">
                        {({ id }) => (
                            <Select
                                id={id}
                                value={filters.category}
                                onChange={(event) => apply({ category: event.target.value })}
                            >
                                <option value="">Any category</option>
                                {categories.map((category) => (
                                    <option key={category.id} value={category.id}>
                                        {category.name}
                                    </option>
                                ))}
                            </Select>
                        )}
                    </Field>
                </div>
            </form>

            <Table
                columns={columns}
                rows={products.data}
                rowKey={(row) => row.publicId}
                caption="Products awaiting a moderation decision"
                empty={
                    <EmptyState
                        title="Nothing is waiting"
                        body="Every proposal has been decided. New ones appear here the moment a seller submits them."
                    />
                }
            />

            {products.lastPage > 1 ? (
                <p className="mt-6 text-[13px] text-[var(--vc-neutral-600)]">
                    Page {products.currentPage} of {products.lastPage} · {products.total} products
                </p>
            ) : null}
        </AdminLayout>
    );
}
