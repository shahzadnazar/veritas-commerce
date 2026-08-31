import { usePage } from '@inertiajs/react';
import { PortalLayout } from '../../design-system/layout/PortalLayout';
import type { NavItem } from '../../design-system/layout/PortalLayout';
import { Button } from '../../design-system/primitives/Button';
import { StatusBadge } from '../../design-system/primitives/StatusBadge';
import { EmptyState } from '../../design-system/patterns/States';
import { Table } from '../../design-system/patterns/Table';
import type { Column } from '../../design-system/patterns/Table';
import type { SharedPageProps } from '../../shared/types';

interface RecentOrder {
    reference: string;
    customer: string;
    total: string;
    earning: string;
    status: string;
}

interface DashboardProps extends SharedPageProps {
    store: { name: string; status: string };
    balance: { clearing: string; available: string; held: string };
    recentOrders: RecentOrder[];
}

const NAV: NavItem[] = [
    { label: 'Dashboard', href: '/seller' },
    { label: 'Products', href: '/seller/offers' },
    { label: 'Inventory', href: '/seller/inventory' },
    { label: 'Orders', href: '/seller/orders' },
    { label: 'Earnings', href: '/seller/earnings' },
    { label: 'Store settings', href: '/seller/store' },
];

/** The seller shell: sidebar, stat strip, order queue. */
export default function Dashboard() {
    const { store, balance, recentOrders, auth } = usePage<DashboardProps>().props;

    const columns: Column<RecentOrder>[] = [
        {
            key: 'reference',
            header: 'Order',
            render: (row) => <span className="vc-tabular">{row.reference}</span>,
        },
        { key: 'customer', header: 'Customer', render: (row) => row.customer },
        { key: 'total', header: 'Total', numeric: true, render: (row) => row.total },
        { key: 'earning', header: 'Your earning', numeric: true, render: (row) => row.earning },
        {
            key: 'status',
            header: 'Status',
            render: (row) => <StatusBadge domain="seller_order" value={row.status} />,
        },
    ];

    return (
        <PortalLayout
            area="Seller portal"
            nav={NAV}
            title="Dashboard"
            identity={{ primary: store.name, secondary: auth.user?.name ?? '' }}
            actions={<Button variant="primary">Add product</Button>}
        >
            <div className="mb-8 grid grid-cols-3 gap-[2px]">
                {[
                    {
                        label: 'Clearing',
                        value: balance.clearing,
                        note: 'Earned, not yet withdrawable',
                    },
                    { label: 'Available', value: balance.available, note: 'Ready to withdraw' },
                    { label: 'Held', value: balance.held, note: 'Against an open request' },
                ].map((stat) => (
                    <div key={stat.label} className="bg-[var(--vc-surface)] p-4">
                        <p className="mb-[6px] text-[11px] tracking-[0.08em] text-[var(--vc-neutral-600)] uppercase">
                            {stat.label}
                        </p>
                        <p className="vc-tabular text-[28px] leading-none font-extrabold">
                            {stat.value}
                        </p>
                        <p className="mt-[6px] text-[12px] text-[var(--vc-neutral-600)]">
                            {stat.note}
                        </p>
                    </div>
                ))}
            </div>

            <h2 className="mb-4 text-[22px]">Recent orders</h2>

            <Table
                columns={columns}
                rows={recentOrders}
                rowKey={(row) => row.reference}
                caption="Recent orders for this store"
                empty={
                    <EmptyState
                        title="No orders yet"
                        body="Orders appear here as soon as a customer buys one of your published offers. Each one arrives with its commission already fixed."
                        actions={<Button variant="secondary">Add a product</Button>}
                    />
                }
            />
        </PortalLayout>
    );
}
