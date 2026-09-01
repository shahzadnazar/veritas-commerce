import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { AdminLayout } from '../../layouts/AdminLayout';
import { Field, Input, Select } from '../../../design-system/primitives/Field';
import { StockCell } from '../../../design-system/patterns/StockCell';
import type { StockLevel } from '../../../design-system/patterns/StockCell';
import { EmptyState, FlashBanner } from '../../../design-system/patterns/States';
import { Table } from '../../../design-system/patterns/Table';
import type { Column } from '../../../design-system/patterns/Table';
import type { SharedPageProps } from '../../../shared/types';

interface InventoryRow extends StockLevel {
    offerPublicId: string;
    sku: string;
    productTitle: string;
    variantName: string | null;
    sellerName: string | null;
    storeName: string | null;
    offerStatus: string;
}

interface IndexProps extends SharedPageProps {
    rows: { data: InventoryRow[]; currentPage: number; lastPage: number; total: number };
    filters: { search: string; state: string };
    states: { value: string; label: string }[];
    can: { adjust: boolean };
}

/**
 * Stock across every seller, worst first.
 *
 * Operational visibility, not warehouse management: the question this page
 * answers is "why can nobody buy this", and the answer is usually on the
 * first row.
 */
export default function Index() {
    const { rows, filters, states, flash } = usePage<IndexProps>().props;
    const [search, setSearch] = useState(filters.search);

    const apply = (changes: Record<string, string>) => {
        router.get('/admin/inventory', { ...filters, search, ...changes }, { preserveState: true });
    };

    const columns: Column<InventoryRow>[] = [
        {
            key: 'listing',
            header: 'Listing',
            render: (row) => (
                <>
                    <Link
                        href={`/admin/inventory/${row.offerPublicId}`}
                        className="font-semibold underline underline-offset-4"
                    >
                        {row.productTitle}
                    </Link>
                    <br />
                    <span className="text-[12px] text-[var(--vc-neutral-600)]">
                        {[row.variantName, row.sku].filter(Boolean).join(' · ')}
                    </span>
                </>
            ),
        },
        {
            key: 'seller',
            header: 'Seller',
            render: (row) => (
                <>
                    {row.sellerName ?? '—'}
                    <br />
                    <span className="text-[12px] text-[var(--vc-neutral-600)]">
                        {row.storeName ?? ''}
                    </span>
                </>
            ),
        },
        { key: 'stock', header: 'Available', render: (row) => <StockCell level={row} /> },
    ];

    return (
        <AdminLayout title="Inventory">
            <FlashBanner success={flash.success} error={flash.error} />

            <form
                className="mb-6 flex flex-wrap items-end gap-3"
                onSubmit={(event) => {
                    event.preventDefault();
                    apply({});
                }}
            >
                <div className="min-w-[240px]">
                    <Field label="Search product or SKU">
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
                    <Field label="Stock state">
                        {({ id }) => (
                            <Select
                                id={id}
                                value={filters.state}
                                onChange={(event) => apply({ state: event.target.value })}
                            >
                                <option value="">Any state</option>
                                {states.map((state) => (
                                    <option key={state.value} value={state.value}>
                                        {state.label}
                                    </option>
                                ))}
                            </Select>
                        )}
                    </Field>
                </div>
            </form>

            <Table
                columns={columns}
                rows={rows.data}
                rowKey={(row) => row.offerPublicId}
                caption="Stock across every seller, lowest availability first"
                empty={
                    <EmptyState
                        title="Nothing stocked yet"
                        body="Listings appear here as sellers create them."
                    />
                }
            />

            {rows.lastPage > 1 ? (
                <p className="mt-6 text-[13px] text-[var(--vc-neutral-600)]">
                    Page {rows.currentPage} of {rows.lastPage} · {rows.total} listings
                </p>
            ) : null}
        </AdminLayout>
    );
}
