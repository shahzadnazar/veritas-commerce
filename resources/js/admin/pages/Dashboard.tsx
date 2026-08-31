import { usePage } from '@inertiajs/react';
import { PortalLayout } from '../../design-system/layout/PortalLayout';
import type { NavItem } from '../../design-system/layout/PortalLayout';
import { StatusBadge } from '../../design-system/primitives/StatusBadge';
import { EmptyState } from '../../design-system/patterns/States';
import { NoFigure, Table } from '../../design-system/patterns/Table';
import type { Column } from '../../design-system/patterns/Table';
import type { SharedPageProps } from '../../shared/types';

interface QueueRow {
    reference: string;
    seller: string;
    total: string;
    /** Null where no payment ever succeeded — rendered as an em dash. */
    commission: string | null;
    status: string;
}

interface AdminDashboardProps extends SharedPageProps {
    queues: { label: string; count: number; note: string }[];
    recentOrders: QueueRow[];
    commissionRate: string;
}

const NAV: NavItem[] = [
    { label: 'Dashboard', href: '/admin' },
    { label: 'Sellers', href: '/admin/sellers' },
    { label: 'Applications', href: '/admin/applications' },
    { label: 'Offer review', href: '/admin/offers' },
    { label: 'Orders', href: '/admin/orders' },
    { label: 'Commission', href: '/admin/commission' },
    { label: 'Payouts', href: '/admin/payouts' },
    { label: 'Settings', href: '/admin/settings' },
];

/**
 * The admin shell.
 *
 * Built as a third Inertia area sharing the same component library rather
 * than a generic CRUD panel (Decision 2) — which is why the table, badge
 * and empty state below are literally the same components the seller
 * portal uses, at a different density.
 */
export default function Dashboard() {
    const { queues, recentOrders, commissionRate, auth } = usePage<AdminDashboardProps>().props;

    const columns: Column<QueueRow>[] = [
        {
            key: 'reference',
            header: 'Order',
            render: (row) => <span className="vc-tabular">{row.reference}</span>,
        },
        { key: 'seller', header: 'Seller', render: (row) => row.seller },
        { key: 'total', header: 'Total', numeric: true, render: (row) => row.total },
        {
            key: 'commission',
            header: 'Commission',
            numeric: true,
            // A failed payment produced no split; an em dash says that,
            // where "$0.00" would claim a commission of zero was taken.
            render: (row) => row.commission ?? <NoFigure />,
        },
        {
            key: 'status',
            header: 'Status',
            render: (row) => <StatusBadge domain="seller_order" value={row.status} />,
        },
    ];

    return (
        <PortalLayout
            area="Admin"
            nav={NAV}
            title="Marketplace control centre"
            identity={{ primary: auth.admin?.name ?? 'Staff', secondary: auth.admin?.role ?? '' }}
        >
            <div className="mb-8 grid grid-cols-4 gap-[2px]">
                {queues.map((queue) => (
                    <div key={queue.label} className="bg-[var(--vc-surface)] p-4">
                        <p className="vc-tabular text-[28px] leading-none font-extrabold">
                            {queue.count}
                        </p>
                        <p className="mt-2 text-[13px] font-semibold">{queue.label}</p>
                        <p className="text-[12px] text-[var(--vc-neutral-600)]">{queue.note}</p>
                    </div>
                ))}
            </div>

            <div className="mb-8 border-2 border-[var(--vc-divider)] p-4">
                <p className="text-[11px] tracking-[0.08em] text-[var(--vc-neutral-600)] uppercase">
                    Platform commission
                </p>
                <p className="vc-tabular text-[28px] font-extrabold">{commissionRate}%</p>
                <p className="mt-1 max-w-[62ch] text-[12px] text-[var(--vc-neutral-700)]">
                    A new rate applies only to orders completed after it takes effect. Every
                    existing order keeps the total, commission, earning and rate written onto it.
                </p>
            </div>

            <h2 className="mb-4 text-[22px]">Recent orders</h2>

            <Table
                columns={columns}
                rows={recentOrders}
                rowKey={(row) => row.reference}
                caption="Recent orders across the marketplace"
                empty={
                    <EmptyState
                        title="No orders yet"
                        body="Orders across every seller appear here once the storefront is trading."
                    />
                }
            />
        </PortalLayout>
    );
}
