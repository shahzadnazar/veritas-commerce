import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { AdminLayout } from '../../layouts/AdminLayout';
import { Button } from '../../../design-system/primitives/Button';
import { StatusBadge } from '../../../design-system/primitives/StatusBadge';
import { Field, Select } from '../../../design-system/primitives/Field';
import { ReasonDialog } from '../../../design-system/patterns/ReasonDialog';
import { EmptyState } from '../../../design-system/patterns/States';
import { Table } from '../../../design-system/patterns/Table';
import type { Column } from '../../../design-system/patterns/Table';
import type { SharedPageProps } from '../../../shared/types';

interface SellerRow {
    publicId: string;
    legalName: string;
    storeName: string | null;
    storeSlug: string | null;
    status: string;
    approvedAt: string | null;
    suspensionReason: string | null;
}

interface AccountsProps extends SharedPageProps {
    sellers: { data: SellerRow[]; currentPage: number; lastPage: number; total: number };
    filters: { status: string };
    can: { suspend: boolean; reactivate: boolean };
}

export default function Accounts() {
    const { sellers, filters, can } = usePage<AccountsProps>().props;
    const [suspending, setSuspending] = useState<SellerRow | null>(null);

    const columns: Column<SellerRow>[] = [
        {
            key: 'store',
            header: 'Store',
            render: (row) => (
                <>
                    <span className="font-semibold">{row.storeName ?? 'Not set up yet'}</span>
                    <br />
                    <span className="text-[var(--vc-neutral-600)]">{row.legalName}</span>
                </>
            ),
        },
        {
            key: 'slug',
            header: 'Address',
            render: (row) => (row.storeSlug ? `/stores/${row.storeSlug}` : '—'),
        },
        { key: 'approved', header: 'Approved', render: (row) => row.approvedAt ?? '—' },
        {
            key: 'status',
            header: 'Status',
            render: (row) => <StatusBadge domain="seller" value={row.status} />,
        },
        {
            key: 'action',
            header: '',
            render: (row) =>
                row.status === 'suspended' ? (
                    can.reactivate ? (
                        <Button
                            variant="secondary"
                            onClick={() => router.post(`/admin/sellers/${row.publicId}/reactivate`)}
                        >
                            Reactivate
                        </Button>
                    ) : null
                ) : can.suspend ? (
                    <Button variant="destructive" onClick={() => setSuspending(row)}>
                        Suspend
                    </Button>
                ) : null,
        },
    ];

    return (
        <AdminLayout title="Sellers">
            <div className="mb-6 flex items-end gap-4">
                <div className="w-[220px]">
                    <Field label="Status">
                        {({ id }) => (
                            <Select
                                id={id}
                                value={filters.status}
                                onChange={(event) =>
                                    router.get(
                                        '/admin/sellers',
                                        { status: event.target.value },
                                        { preserveState: true, replace: true },
                                    )
                                }
                            >
                                <option value="">All</option>
                                <option value="approved">Approved</option>
                                <option value="suspended">Suspended</option>
                                <option value="pending">Pending</option>
                            </Select>
                        )}
                    </Field>
                </div>
                <p className="vc-tabular ml-auto text-[13px] text-[var(--vc-neutral-600)]">
                    {sellers.total} seller{sellers.total === 1 ? '' : 's'}
                </p>
            </div>

            <Table
                columns={columns}
                rows={sellers.data}
                rowKey={(row) => row.publicId}
                caption="Sellers on the marketplace"
                empty={
                    <EmptyState
                        title="No sellers yet"
                        body="Approved applications appear here as trading sellers."
                    />
                }
            />

            <ReasonDialog
                open={suspending !== null}
                title={`Suspend ${suspending?.storeName ?? suspending?.legalName ?? 'this seller'}?`}
                consequence="Their listings leave the storefront and their balance is frozen against payout. Open orders are not cancelled — they must still be fulfilled — and nothing is deleted."
                confirmLabel="Suspend seller"
                action={suspending ? `/admin/sellers/${suspending.publicId}/suspend` : ''}
                onClose={() => setSuspending(null)}
            />
        </AdminLayout>
    );
}
