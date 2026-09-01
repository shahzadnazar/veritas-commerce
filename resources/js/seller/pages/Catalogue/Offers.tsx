import { Link, router, usePage } from '@inertiajs/react';
import { SellerLayout } from '../../layouts/SellerLayout';
import { Button } from '../../../design-system/primitives/Button';
import { StatusBadge } from '../../../design-system/primitives/StatusBadge';
import { EmptyState, FlashBanner } from '../../../design-system/patterns/States';
import { Table } from '../../../design-system/patterns/Table';
import type { Column } from '../../../design-system/patterns/Table';
import type { SharedPageProps } from '../../../shared/types';

interface OfferRow {
    publicId: string;
    sku: string;
    productTitle: string;
    productSlug: string | null;
    variantName: string | null;
    price: string;
    condition: string;
    conditionLabel: string;
    status: string;
    canPublish: boolean;
}

interface OffersProps extends SharedPageProps {
    offers: { data: OfferRow[]; currentPage: number; lastPage: number; total: number };
    can: { manage: boolean };
}

export default function Offers() {
    const { offers, can, flash } = usePage<OffersProps>().props;

    const columns: Column<OfferRow>[] = [
        {
            key: 'product',
            header: 'Product',
            render: (row) => (
                <>
                    <span className="block font-semibold">{row.productTitle}</span>
                    <span className="block text-[12px] text-[var(--vc-neutral-600)]">
                        {row.variantName ? `${row.variantName} · ` : ''}
                        SKU {row.sku}
                    </span>
                </>
            ),
        },
        { key: 'condition', header: 'Condition', render: (row) => row.conditionLabel },
        {
            key: 'price',
            header: 'Your price',
            numeric: true,
            render: (row) => <span className="vc-tabular">{row.price}</span>,
        },
        {
            key: 'status',
            header: 'Status',
            render: (row) => <StatusBadge domain="offer" value={row.status} />,
        },
        {
            key: 'action',
            header: '',
            render: (row) =>
                !can.manage ? null : row.status === 'published' ? (
                    <Button
                        variant="ghost"
                        onClick={() =>
                            router.post(
                                `/seller/offers/${row.publicId}/status`,
                                { status: 'draft' },
                                { preserveScroll: true },
                            )
                        }
                    >
                        Pause
                    </Button>
                ) : row.canPublish ? (
                    <Button
                        variant="secondary"
                        onClick={() =>
                            router.post(
                                `/seller/offers/${row.publicId}/status`,
                                { status: 'published' },
                                { preserveScroll: true },
                            )
                        }
                    >
                        Put live
                    </Button>
                ) : null,
        },
    ];

    return (
        <SellerLayout
            title="Your listings"
            actions={
                <Link href="/seller/products">
                    <Button variant="primary">Add a listing</Button>
                </Link>
            }
        >
            <FlashBanner success={flash.success} error={flash.error} />

            <Table
                columns={columns}
                rows={offers.data}
                rowKey={(row) => row.publicId}
                caption="This store's listings against marketplace products"
                empty={
                    <EmptyState
                        title="Nothing listed yet"
                        body="Find a product in the marketplace catalogue and set your price against it. Your listing appears on the same page as every other seller's, which is where customers compare."
                        actions={
                            <Link href="/seller/products">
                                <Button variant="primary">Find a product</Button>
                            </Link>
                        }
                    />
                }
            />

            {offers.lastPage > 1 ? (
                <p className="mt-6 text-[13px] text-[var(--vc-neutral-600)]">
                    Page {offers.currentPage} of {offers.lastPage} · {offers.total} listings
                </p>
            ) : null}
        </SellerLayout>
    );
}
