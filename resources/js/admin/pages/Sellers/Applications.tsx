import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { AdminLayout } from '../../layouts/AdminLayout';
import { StatusBadge } from '../../../design-system/primitives/StatusBadge';
import { Field, Input, Select } from '../../../design-system/primitives/Field';
import { EmptyState } from '../../../design-system/patterns/States';
import { Table } from '../../../design-system/patterns/Table';
import type { Column } from '../../../design-system/patterns/Table';
import type { SharedPageProps } from '../../../shared/types';

interface ApplicationRow {
    publicId: string;
    reference: string;
    legalName: string;
    tradingName: string;
    contactEmail: string;
    status: string;
    submittedAt: string | null;
    reviewer: string | null;
}

interface ApplicationsProps extends SharedPageProps {
    applications: { data: ApplicationRow[]; currentPage: number; lastPage: number; total: number };
    filters: { status: string; search: string };
    statuses: { value: string; label: string }[];
}

export default function Applications() {
    const { applications, filters, statuses } = usePage<ApplicationsProps>().props;
    const [search, setSearch] = useState(filters.search);

    // Filters live in the URL, so a filtered queue is shareable and the
    // back button behaves.
    const applyFilter = (next: Partial<{ status: string; search: string }>) => {
        router.get(
            '/admin/applications',
            { ...filters, ...next },
            { preserveState: true, replace: true },
        );
    };

    const columns: Column<ApplicationRow>[] = [
        {
            key: 'reference',
            header: 'Reference',
            render: (row) => (
                <Link href={`/admin/applications/${row.publicId}`} className="vc-tabular underline">
                    {row.reference}
                </Link>
            ),
        },
        {
            key: 'business',
            header: 'Business',
            render: (row) => (
                <>
                    <span className="font-semibold">{row.tradingName}</span>
                    <br />
                    <span className="text-[var(--vc-neutral-600)]">{row.legalName}</span>
                </>
            ),
        },
        { key: 'contact', header: 'Contact', render: (row) => row.contactEmail },
        { key: 'submitted', header: 'Submitted', render: (row) => row.submittedAt ?? '—' },
        { key: 'reviewer', header: 'Reviewer', render: (row) => row.reviewer ?? 'Unassigned' },
        {
            key: 'status',
            header: 'Status',
            render: (row) => <StatusBadge domain="seller_application" value={row.status} />,
        },
    ];

    return (
        <AdminLayout title="Seller applications">
            <div className="mb-6 flex flex-wrap items-end gap-4">
                <div className="w-[260px]">
                    <Field label="Search">
                        {({ id }) => (
                            <Input
                                id={id}
                                type="search"
                                placeholder="Reference, business or email"
                                value={search}
                                onChange={(event) => setSearch(event.target.value)}
                                onBlur={() => applyFilter({ search })}
                                onKeyDown={(event) => {
                                    if (event.key === 'Enter') {
                                        applyFilter({ search });
                                    }
                                }}
                            />
                        )}
                    </Field>
                </div>

                <div className="w-[220px]">
                    <Field label="Status">
                        {({ id }) => (
                            <Select
                                id={id}
                                value={filters.status}
                                onChange={(event) => applyFilter({ status: event.target.value })}
                            >
                                <option value="">Waiting on us</option>
                                <option value="all">All</option>
                                {statuses.map((status) => (
                                    <option key={status.value} value={status.value}>
                                        {status.label}
                                    </option>
                                ))}
                            </Select>
                        )}
                    </Field>
                </div>

                <p className="vc-tabular ml-auto text-[13px] text-[var(--vc-neutral-600)]">
                    {applications.total} application{applications.total === 1 ? '' : 's'}
                </p>
            </div>

            <Table
                columns={columns}
                rows={applications.data}
                rowKey={(row) => row.publicId}
                caption="Seller applications awaiting a decision"
                empty={
                    <EmptyState
                        title="Nothing waiting"
                        body="No applications match this view. Switch the status filter to All to see decided ones."
                    />
                }
            />
        </AdminLayout>
    );
}
