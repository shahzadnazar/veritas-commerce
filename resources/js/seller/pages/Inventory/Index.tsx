import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { SellerLayout } from '../../layouts/SellerLayout';
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
    productSlug: string | null;
    variantName: string | null;
    storeName: string | null;
    offerStatus: string;
}

interface IndexProps extends SharedPageProps {
    rows: { data: InventoryRow[]; currentPage: number; lastPage: number; total: number };
    filters: { search: string; state: string };
    states: { value: string; label: string }[];
    can: { manage: boolean };
}

/**
 * Every listing this seller stocks, lowest availability first.
 *
 * The ordering is the whole point of the page: a stock list sorted by name
 * makes you hunt for the problem, one sorted by what is about to run out
 * puts it on the first row.
 */
export default function Index() {
    const { rows, filters, states, flash } = usePage<IndexProps>().props;
    const [search, setSearch] = useState(filters.search);

    const apply = (changes: Record<string, string>) => {
        router.get(
            '/seller/inventory',
            { ...filters, search, ...changes },
            { preserveState: true },
        );
    };

    const columns: Column<InventoryRow>[] = [
        {
            key: 'listing',
            header: 'Listing',
            render: (row) => (
                <>
                    <Link
                        href={`/seller/inventory/${row.offerPublicId}`}
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
        { key: 'stock', header: 'Available', render: (row) => <StockCell level={row} /> },
        {
            key: 'threshold',
            header: 'Warns at',
            render: (row) =>
                row.threshold > 0 ? (
                    <span className="vc-tabular">{row.threshold}</span>
                ) : (
                    <span className="text-[12px] text-[var(--vc-neutral-600)]">Never</span>
                ),
        },
    ];

    return (
        <SellerLayout title="Inventory">
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
                caption="Stock for your listings, lowest availability first"
                empty={
                    <EmptyState
                        title="No listings yet"
                        body="Stock appears here once you list a product. Every listing you create gets a row, even before you have counted it."
                    />
                }
            />

            {rows.lastPage > 1 ? (
                <p className="mt-6 text-[13px] text-[var(--vc-neutral-600)]">
                    Page {rows.currentPage} of {rows.lastPage} · {rows.total} listings
                </p>
            ) : null}
        </SellerLayout>
    );
}
