import { usePage } from '@inertiajs/react';
import { useState } from 'react';
import { AdminLayout } from '../../layouts/AdminLayout';
import { Button } from '../../../design-system/primitives/Button';
import { ReasonDialog } from '../../../design-system/patterns/ReasonDialog';
import { FlashBanner } from '../../../design-system/patterns/States';
import { Table } from '../../../design-system/patterns/Table';
import type { Column } from '../../../design-system/patterns/Table';
import type { SharedPageProps } from '../../../shared/types';

interface StaffRow {
    publicId: string;
    name: string;
    email: string;
    role: string;
    roleLabel: string;
    twoFactorConfirmed: boolean;
    lastSignedInAt: string | null;
}

interface StaffProps extends SharedPageProps {
    staff: StaffRow[];
}

/**
 * Staff accounts.
 *
 * The only column about someone's second factor is whether they have one.
 * The secret, and every recovery code, is unreadable from here by design.
 */
export default function Index() {
    const { staff, auth, flash } = usePage<StaffProps>().props;
    const [resetting, setResetting] = useState<StaffRow | null>(null);

    const columns: Column<StaffRow>[] = [
        {
            key: 'person',
            header: 'Person',
            render: (row) => (
                <>
                    <span className="font-semibold">{row.name}</span>
                    <br />
                    <span className="text-[var(--vc-neutral-600)]">{row.email}</span>
                </>
            ),
        },
        { key: 'role', header: 'Role', render: (row) => row.roleLabel },
        {
            key: 'mfa',
            header: 'Second factor',
            render: (row) => (row.twoFactorConfirmed ? 'Enrolled' : 'Not enrolled'),
        },
        { key: 'seen', header: 'Last signed in', render: (row) => row.lastSignedInAt ?? 'Never' },
        {
            key: 'action',
            header: '',
            render: (row) =>
                row.twoFactorConfirmed && row.publicId !== auth.admin?.publicId ? (
                    <Button variant="destructive" onClick={() => setResetting(row)}>
                        Reset second factor
                    </Button>
                ) : null,
        },
    ];

    return (
        <AdminLayout title="Staff">
            <FlashBanner success={flash.success} error={flash.error} />

            <Table
                columns={columns}
                rows={staff}
                rowKey={(row) => row.publicId}
                caption="Staff accounts and their second-factor state"
            />

            <ReasonDialog
                open={resetting !== null}
                title={`Reset the second factor for ${resetting?.name ?? ''}?`}
                consequence="They will sign in with their password alone once, and must enrol a new authenticator before they can reach anything. Every existing recovery code stops working. This is recorded against your account."
                confirmLabel="Reset second factor"
                action={`/admin/staff/${resetting?.publicId ?? ''}/reset-two-factor`}
                onClose={() => setResetting(null)}
            />
        </AdminLayout>
    );
}
