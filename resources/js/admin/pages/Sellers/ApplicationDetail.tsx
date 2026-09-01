import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { AdminLayout } from '../../layouts/AdminLayout';
import { Button } from '../../../design-system/primitives/Button';
import { StatusBadge } from '../../../design-system/primitives/StatusBadge';
import { ReasonDialog } from '../../../design-system/patterns/ReasonDialog';
import type { SharedPageProps } from '../../../shared/types';

interface HistoryEntry {
    fromStatus: string | null;
    toStatus: string;
    actorType: string;
    reason: string | null;
    at: string | null;
}

interface ApplicationDetailProps extends SharedPageProps {
    application: {
        publicId: string;
        reference: string;
        status: string;
        legalName: string;
        tradingName: string;
        businessType: string;
        taxId: string | null;
        address: string;
        website: string | null;
        contactName: string;
        contactEmail: string;
        contactPhone: string | null;
        intendedCategories: string[];
        expectedCatalogueType: string | null;
        blurb: string | null;
        operationalNotes: string | null;
        decisionReason: string | null;
        submittedAt: string | null;
        reviewer: string | null;
    };
    documents: {
        kind: string;
        kindLabel: string;
        originalName: string;
        bytes: number;
        uploadedAt: string | null;
        /** Null unless this reviewer is cleared to open it. */
        downloadUrl: string | null;
    }[];
    history: HistoryEntry[];
    can: { review: boolean; approve: boolean; reject: boolean };
}

function Detail({ label, value }: { label: string; value: string | null }) {
    return (
        <div className="border-b border-[var(--vc-divider)] py-3">
            <dt className="text-[11px] tracking-[0.08em] text-[var(--vc-neutral-600)] uppercase">
                {label}
            </dt>
            <dd className="mt-1">{value ?? '—'}</dd>
        </div>
    );
}

export default function ApplicationDetail() {
    const { application, documents, history, can } = usePage<ApplicationDetailProps>().props;
    const [dialog, setDialog] = useState<'reject' | 'changes' | null>(null);

    const base = `/admin/applications/${application.publicId}`;
    const open = ['submitted', 'under_review', 'changes_requested'].includes(application.status);

    return (
        <AdminLayout
            title={application.reference}
            actions={
                open ? (
                    <>
                        {can.review && application.status === 'submitted' ? (
                            <Button
                                variant="secondary"
                                onClick={() => router.post(`${base}/review`)}
                            >
                                Begin review
                            </Button>
                        ) : null}
                        {can.review ? (
                            <Button variant="secondary" onClick={() => setDialog('changes')}>
                                Request changes
                            </Button>
                        ) : null}
                        {can.reject ? (
                            <Button variant="destructive" onClick={() => setDialog('reject')}>
                                Reject
                            </Button>
                        ) : null}
                        {can.approve ? (
                            <Button
                                variant="primary"
                                onClick={() => router.post(`${base}/approve`)}
                            >
                                Approve seller
                            </Button>
                        ) : null}
                    </>
                ) : null
            }
        >
            <div className="mb-8 flex flex-wrap items-center gap-3">
                <StatusBadge domain="seller_application" value={application.status} />
                <span className="text-[13px] text-[var(--vc-neutral-600)]">
                    Submitted {application.submittedAt ?? '—'} · reviewer{' '}
                    {application.reviewer ?? 'unassigned'}
                </span>
            </div>

            {application.decisionReason ? (
                <div className="mb-8 border-2 border-[var(--vc-accent)] p-4">
                    <p className="mb-1 text-[11px] tracking-[0.08em] text-[var(--vc-accent-800)] uppercase">
                        Reason sent to the applicant
                    </p>
                    <p>{application.decisionReason}</p>
                </div>
            ) : null}

            <div className="grid gap-10 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                <div>
                    <h2 className="mb-2 text-[20px]">Business</h2>
                    <dl className="mb-8">
                        <Detail label="Legal name" value={application.legalName} />
                        <Detail label="Trading name" value={application.tradingName} />
                        <Detail label="Business type" value={application.businessType} />
                        <Detail
                            label="Tax ID"
                            value={application.taxId ?? 'Hidden — requires seller.view_sensitive'}
                        />
                        <Detail label="Address" value={application.address} />
                        <Detail label="Website" value={application.website} />
                    </dl>

                    <h2 className="mb-2 text-[20px]">Contact</h2>
                    <dl className="mb-8">
                        <Detail label="Name" value={application.contactName} />
                        <Detail label="Email" value={application.contactEmail} />
                        <Detail label="Phone" value={application.contactPhone} />
                    </dl>

                    <h2 className="mb-2 text-[20px]">Marketplace</h2>
                    <dl className="mb-8">
                        <Detail
                            label="Intended categories"
                            value={application.intendedCategories.join(', ') || null}
                        />
                        <Detail label="Catalogue type" value={application.expectedCatalogueType} />
                        <Detail label="What they sell" value={application.blurb} />
                        <Detail label="Operational notes" value={application.operationalNotes} />
                    </dl>

                    <h2 className="mb-2 text-[20px]">Documents</h2>
                    {documents.length === 0 ? (
                        <p className="text-[var(--vc-neutral-700)]">
                            No documents were required or supplied.
                        </p>
                    ) : (
                        <ul className="border-t-2 border-[var(--vc-text)]">
                            {documents.map((document) => (
                                <li
                                    key={document.originalName}
                                    className="flex flex-wrap items-center gap-3 border-b border-[var(--vc-divider)] py-3"
                                >
                                    <span className="flex-1 text-[14px]">
                                        <span className="block font-semibold">
                                            {document.kindLabel}
                                        </span>
                                        <span className="block text-[12px] text-[var(--vc-neutral-600)]">
                                            {document.originalName}
                                            {document.uploadedAt ? ` · ${document.uploadedAt}` : ''}
                                        </span>
                                    </span>

                                    {document.downloadUrl ? (
                                        <a
                                            href={document.downloadUrl}
                                            className="text-[13px] underline underline-offset-4"
                                        >
                                            Download
                                        </a>
                                    ) : (
                                        <span className="text-[12px] text-[var(--vc-neutral-600)]">
                                            Your role cannot open verification documents
                                        </span>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>

                <aside>
                    <h2 className="mb-2 text-[20px]">History</h2>
                    <ol className="border-t-2 border-[var(--vc-text)]">
                        {history.map((entry, index) => (
                            <li key={index} className="border-b border-[var(--vc-divider)] py-3">
                                <p className="text-[13px]">
                                    <span className="font-semibold">{entry.toStatus}</span>
                                    {entry.fromStatus ? ` from ${entry.fromStatus}` : ''} ·{' '}
                                    {entry.actorType}
                                </p>
                                <p className="text-[12px] text-[var(--vc-neutral-600)]">
                                    {entry.at}
                                </p>
                                {entry.reason ? (
                                    <p className="mt-1 text-[13px]">{entry.reason}</p>
                                ) : null}
                            </li>
                        ))}
                    </ol>
                </aside>
            </div>

            <ReasonDialog
                open={dialog === 'reject'}
                title="Reject this application?"
                consequence="The applicant is told they were not approved and sees this reason verbatim. Rejection is final for this application; they would need to apply again."
                confirmLabel="Reject application"
                action={`${base}/reject`}
                onClose={() => setDialog(null)}
            />

            <ReasonDialog
                open={dialog === 'changes'}
                title="Ask for changes?"
                consequence="The application returns to the applicant to correct and resend. It stays open, and this reason is what they will see."
                confirmLabel="Request changes"
                action={`${base}/request-changes`}
                onClose={() => setDialog(null)}
            />
        </AdminLayout>
    );
}
