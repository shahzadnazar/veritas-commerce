import type { ReactNode } from 'react';
import { usePage } from '@inertiajs/react';
import { PortalLayout } from '../../design-system/layout/PortalLayout';
import type { NavItem } from '../../design-system/layout/PortalLayout';
import type { SharedPageProps } from '../../shared/types';

const NAV: NavItem[] = [
    { label: 'Dashboard', href: '/seller' },
    { label: 'Store', href: '/seller/store' },
    { label: 'Products', href: '/seller/products' },
    { label: 'Offers', href: '/seller/offers' },
    { label: 'Inventory', href: '/seller/inventory' },
    { label: 'Orders', href: '/seller/orders' },
    // Two entries rather than one, because they answer different
    // questions: what have I made, and where has it gone. Both are gated
    // server-side — a link is a courtesy, never the control (§47).
    { label: 'Earnings', href: '/seller/earnings' },
    { label: 'Payouts', href: '/seller/payouts' },
    { label: 'Analytics', href: '/seller/analytics' },
    { label: 'Team', href: '/seller/team' },
];

/**
 * The seller chrome — the same PortalLayout the admin area composes, with
 * a different navigation set.
 */
export function SellerLayout({
    title,
    actions,
    children,
}: {
    title: string;
    actions?: ReactNode;
    children: ReactNode;
}) {
    const { auth } = usePage<SharedPageProps>().props;

    return (
        <PortalLayout
            area="Seller portal"
            nav={NAV}
            title={title}
            actions={actions}
            identity={{
                primary: auth.seller?.storeName ?? 'Your store',
                secondary: auth.user?.name ?? '',
            }}
        >
            {children}
        </PortalLayout>
    );
}
