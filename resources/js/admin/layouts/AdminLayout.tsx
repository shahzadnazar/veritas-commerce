import type { ReactNode } from 'react';
import { usePage } from '@inertiajs/react';
import { PortalLayout } from '../../design-system/layout/PortalLayout';
import type { NavItem } from '../../design-system/layout/PortalLayout';
import type { SharedPageProps } from '../../shared/types';

const NAV: NavItem[] = [
    { label: 'Dashboard', href: '/admin' },
    { label: 'Applications', href: '/admin/applications' },
    { label: 'Sellers', href: '/admin/sellers' },
    { label: 'Staff', href: '/admin/staff' },
    { label: 'Two-factor', href: '/admin/two-factor' },
];

/**
 * The admin chrome.
 *
 * Composes the shared PortalLayout rather than defining a second visual
 * system — the seller portal uses the same component with a different
 * navigation set, which is the whole point of one design system.
 */
export function AdminLayout({
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
            area="Admin"
            nav={NAV}
            title={title}
            actions={actions}
            identity={{ primary: auth.admin?.name ?? 'Staff', secondary: auth.admin?.role ?? '' }}
        >
            {children}
        </PortalLayout>
    );
}
