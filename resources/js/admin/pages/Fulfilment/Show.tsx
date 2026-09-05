import { Link, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Modal } from '../../../design-system/patterns/Modal';
import { Button } from '../../../design-system/primitives/Button';
import { Field, Input, Textarea } from '../../../design-system/primitives/Field';
import { StatusBadge } from '../../../design-system/primitives/StatusBadge';
import type {
    OrderFulfilmentSummary,
    SellerFulfilmentView,
    ShipmentView,
} from '../../../shared/commerce';
import type { SharedPageProps } from '../../../shared/types';
import { AdminLayout } from '../../layouts/AdminLayout';

interface EarningRow {
    publicId: string;
    type: string;
    status: string;
    amountMinor: number;
    amount: string;
    availableAt: string | null;
    note: string | null;
    reversesEntryId: number | null;
    createdAt: string;
    isAvailable: boolean;
}

interface ShowProps extends SharedPageProps {
    sellerOrder: {
        reference: string;
        status: string;
        storeName: string;
        confirmedAt: string | null;
        packedAt: string | null;
        shippedAt: string | null;
        deliveredAt: string | null;
        completedAt: string | null;
        earningsClearAt: string | null;
        orderTotal: string;
    };
    parent: { reference: string; status: string; summary: OrderFulfilmentSummary };
    fulfilment: SellerFulfilmentView;
    earnings: EarningRow[];
    carriers: { code: string; name: string }[];
    can: { override: boolean; correctTracking: boolean; viewClearing: boolean };
}

/**
 * One seller order's fulfilment, end to end, for the platform.
 *
 * Two corrective actions and no more: record a delivery, and fix a
 * tracking number. Both take a written reason and both run the same domain
 * action a seller would, so an operator cannot put an order into a state
 * the domain has no route to. There is deliberately no status dropdown —
 * the next person to read the order would have no way of knowing how it
 * got where it is.
 */
export default function Show() {
    const { sellerOrder, parent, fulfilment, earnings, carriers, can } = usePage<ShowProps>().props;

    return (
        <AdminLayout title={`Fulfilment — ${sellerOrder.reference}`}>
            <p className="mb-6 text-[13px]">
                <Link href="/admin/fulfilment" className="underline underline-offset-4">
                    All fulfilment
                </Link>
                {' · '}
                <Link
                    href={`/admin/orders/${parent.reference}`}
                    className="underline underline-offset-4"
                >
                    Order {parent.reference}
                </Link>
            </p>

            <section className="mb-8 border-2 border-[var(--vc-text)] p-5">
                <div className="flex flex-wrap items-baseline gap-x-6 gap-y-2">
                    <span className="flex items-baseline gap-3">
                        <span className="text-[20px]">{sellerOrder.storeName}</span>
                        <StatusBadge domain="seller_order" value={sellerOrder.status} />
                    </span>
                    <span className="vc-tabular text-[14px]">{sellerOrder.orderTotal}</span>
                </div>

                <p className="mt-2 text-[13px] text-[var(--vc-neutral-700)]">
                    Whole order: {parent.summary.label} — {parent.summary.detail}
                </p>

                <dl className="mt-4 grid gap-x-6 gap-y-2 text-[13px] sm:grid-cols-3">
                    <Stamp label="Confirmed" at={sellerOrder.confirmedAt} />
                    <Stamp label="Packed" at={sellerOrder.packedAt} />
                    <Stamp label="Sent" at={sellerOrder.shippedAt} />
                    <Stamp label="Delivered" at={sellerOrder.deliveredAt} />
                    <Stamp label="Earnings clear" at={sellerOrder.earningsClearAt} />
                    <Stamp label="Completed" at={sellerOrder.completedAt} />
                </dl>
            </section>

            <section className="mb-10">
                <h2 className="mb-3 text-[20px]">Items</h2>
                <ul className="border-t border-[var(--vc-divider)]">
                    {fulfilment.items.map((item) => (
                        <li
                            key={item.orderItemId}
                            className="flex flex-wrap justify-between gap-x-4 gap-y-1 border-b border-[var(--vc-divider)] py-3 text-[14px]"
                        >
                            <span>
                                {item.title}
                                <span className="block text-[12px] text-[var(--vc-neutral-600)]">
                                    {item.sku}
                                </span>
                            </span>
                            <span className="vc-tabular text-[13px]">
                                {item.ordered} ordered · {item.shipped} sent · {item.delivered}{' '}
                                delivered
                                {item.refunded > 0 ? ` · ${item.refunded} refunded` : ''}
                            </span>
                        </li>
                    ))}
                </ul>
            </section>

            <section className="mb-10">
                <h2 className="mb-3 text-[20px]">Parcels</h2>

                {fulfilment.shipments.length === 0 ? (
                    <p className="text-[14px] text-[var(--vc-neutral-700)]">
                        Nothing has been packed for this order.
                    </p>
                ) : (
                    <ul className="border-t-2 border-[var(--vc-text)]">
                        {fulfilment.shipments.map((shipment) => (
                            <AdminShipment
                                key={shipment.publicId}
                                shipment={shipment}
                                reference={sellerOrder.reference}
                                carriers={carriers}
                                can={can}
                            />
                        ))}
                    </ul>
                )}
            </section>

            {can.viewClearing ? (
                <section className="mb-10">
                    <h2 className="mb-1 text-[20px]">Seller earnings</h2>
                    <p className="mb-3 text-[13px] text-[var(--vc-neutral-700)]">
                        From the ledger, which is the financial record — not summed from the
                        order&rsquo;s own totals.
                    </p>

                    {earnings.length === 0 ? (
                        <p className="text-[14px] text-[var(--vc-neutral-700)]">
                            Nothing has been posted for this order yet.
                        </p>
                    ) : (
                        <ul className="border-t border-[var(--vc-divider)]">
                            {earnings.map((entry) => (
                                <li
                                    key={entry.publicId}
                                    className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 border-b border-[var(--vc-divider)] py-3 text-[14px]"
                                >
                                    <span className="flex flex-wrap items-baseline gap-3">
                                        <StatusBadge
                                            domain="ledger_entry_type"
                                            value={entry.type}
                                        />
                                        <StatusBadge
                                            domain="ledger_entry_status"
                                            value={entry.status}
                                        />
                                        {entry.availableAt ? (
                                            <span className="text-[12px] text-[var(--vc-neutral-600)]">
                                                available{' '}
                                                <time dateTime={entry.availableAt}>
                                                    {new Date(
                                                        entry.availableAt,
                                                    ).toLocaleDateString()}
                                                </time>
                                            </span>
                                        ) : null}
                                    </span>
                                    <span className="vc-tabular whitespace-nowrap">
                                        {entry.amountMinor < 0 ? '−' : ''}
                                        {entry.amount}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            ) : null}

            {fulfilment.issues.length > 0 ? (
                <section className="mb-10">
                    <h2 className="mb-3 text-[20px]">Reported problems</h2>
                    <ul className="border-t border-[var(--vc-divider)]">
                        {fulfilment.issues.map((issue) => (
                            <li
                                key={issue.publicId}
                                className="border-b border-[var(--vc-divider)] py-3 text-[14px]"
                            >
                                <StatusBadge domain="fulfilment_issue" value={issue.reason} />
                                <p className="mt-1 text-[var(--vc-neutral-700)]">{issue.note}</p>
                            </li>
                        ))}
                    </ul>
                </section>
            ) : null}
        </AdminLayout>
    );
}

function Stamp({ label, at }: { label: string; at: string | null }) {
    return (
        <div>
            <dt className="text-[12px] tracking-[0.08em] text-[var(--vc-neutral-600)] uppercase">
                {label}
            </dt>
            <dd className="vc-tabular">
                {at ? <time dateTime={at}>{new Date(at).toLocaleString()}</time> : '—'}
            </dd>
        </div>
    );
}

function AdminShipment({
    shipment,
    reference,
    carriers,
    can,
}: {
    shipment: ShipmentView;
    reference: string;
    carriers: { code: string; name: string }[];
    can: { override: boolean; correctTracking: boolean };
}) {
    const [dialog, setDialog] = useState<'deliver' | 'tracking' | null>(null);
    const parcel = `/admin/fulfilment/${reference}/shipments/${shipment.publicId}`;

    return (
        <li className="border-b border-[var(--vc-divider)] py-4">
            <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-2">
                <span className="flex flex-wrap items-baseline gap-3">
                    <span className="vc-tabular font-semibold">{shipment.reference}</span>
                    <StatusBadge domain="shipment" value={shipment.status} />
                    {shipment.carrierName ? (
                        <span className="text-[13px]">
                            {shipment.carrierName}
                            {shipment.trackingNumber ? (
                                <span className="vc-tabular"> · {shipment.trackingNumber}</span>
                            ) : null}
                        </span>
                    ) : null}
                </span>

                <span className="flex flex-wrap gap-2">
                    {can.override && shipment.canDeliver ? (
                        <Button variant="destructive" onClick={() => setDialog('deliver')}>
                            Record delivery
                        </Button>
                    ) : null}
                    {can.correctTracking ? (
                        <Button variant="ghost" onClick={() => setDialog('tracking')}>
                            Correct tracking
                        </Button>
                    ) : null}
                </span>
            </div>

            {shipment.history && shipment.history.length > 0 ? (
                <ol className="mt-2 text-[12px] text-[var(--vc-neutral-700)]">
                    {shipment.history.map((entry, index) => (
                        <li key={`${entry.at}-${index}`}>
                            <time dateTime={entry.at}>{new Date(entry.at).toLocaleString()}</time> —{' '}
                            {entry.from && entry.from !== entry.to
                                ? `${entry.from} → ${entry.to}`
                                : entry.to}
                            {` · ${entry.actorType}`}
                            {entry.trackingNumber ? ` · ${entry.trackingNumber}` : ''}
                            {entry.reason ? ` · ${entry.reason}` : ''}
                        </li>
                    ))}
                </ol>
            ) : null}

            <OverrideDialog
                open={dialog === 'deliver'}
                onClose={() => setDialog(null)}
                action={`${parcel}/deliver`}
                title={`Record ${shipment.reference} as delivered`}
                consequence={
                    'This contradicts the seller’s own record of their shipment, starts their earnings ' +
                    'clearing, and cannot be undone. It is kept in the parcel’s history with your reason.'
                }
                confirmLabel="Record delivery"
            />

            <TrackingDialog
                open={dialog === 'tracking'}
                onClose={() => setDialog(null)}
                action={`${parcel}/tracking`}
                shipment={shipment}
                carriers={carriers}
            />
        </li>
    );
}

function OverrideDialog({
    open,
    onClose,
    action,
    title,
    consequence,
    confirmLabel,
}: {
    open: boolean;
    onClose: () => void;
    action: string;
    title: string;
    consequence: string;
    confirmLabel: string;
}) {
    const form = useForm({ reason: '' });

    return (
        <Modal
            open={open}
            title={title}
            consequence={consequence}
            onClose={onClose}
            actions={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        variant="destructive"
                        loading={form.processing}
                        loadingLabel="Recording…"
                        disabled={form.data.reason.trim().length < 8}
                        onClick={() =>
                            form.post(action, {
                                preserveScroll: true,
                                onSuccess: () => {
                                    form.reset();
                                    onClose();
                                },
                            })
                        }
                    >
                        {confirmLabel}
                    </Button>
                </>
            }
        >
            <Field
                label="Reason — kept in the parcel’s history"
                error={form.errors.reason}
                hint="Say what you know and how you know it."
            >
                {({ id, describedBy, invalid }) => (
                    <Textarea
                        id={id}
                        aria-describedby={describedBy}
                        invalid={invalid}
                        value={form.data.reason}
                        onChange={(event) => form.setData('reason', event.target.value)}
                    />
                )}
            </Field>
        </Modal>
    );
}

function TrackingDialog({
    open,
    onClose,
    action,
    shipment,
    carriers,
}: {
    open: boolean;
    onClose: () => void;
    action: string;
    shipment: ShipmentView;
    carriers: { code: string; name: string }[];
}) {
    const form = useForm({
        carrier: shipment.carrierName ?? '',
        tracking_number: shipment.trackingNumber ?? '',
        reason: '',
    });

    return (
        <Modal
            open={open}
            title={`Correct tracking on ${shipment.reference}`}
            consequence={
                'The value being replaced stays in the parcel’s history, so what the customer was ' +
                'originally told remains readable.'
            }
            onClose={onClose}
            actions={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        variant="primary"
                        loading={form.processing}
                        loadingLabel="Saving…"
                        disabled={form.data.reason.trim().length < 8}
                        onClick={() =>
                            form.post(action, {
                                preserveScroll: true,
                                onSuccess: () => {
                                    form.reset();
                                    onClose();
                                },
                            })
                        }
                    >
                        Correct tracking
                    </Button>
                </>
            }
        >
            <div className="grid gap-3 sm:grid-cols-2">
                <Field label="Carrier" error={form.errors.carrier}>
                    {({ id, describedBy, invalid }) => (
                        <Input
                            id={id}
                            list="admin-carriers"
                            aria-describedby={describedBy}
                            invalid={invalid}
                            value={form.data.carrier}
                            onChange={(event) => form.setData('carrier', event.target.value)}
                        />
                    )}
                </Field>

                <Field label="Tracking number" error={form.errors.tracking_number}>
                    {({ id, describedBy, invalid }) => (
                        <Input
                            id={id}
                            aria-describedby={describedBy}
                            invalid={invalid}
                            value={form.data.tracking_number}
                            onChange={(event) =>
                                form.setData('tracking_number', event.target.value)
                            }
                        />
                    )}
                </Field>
            </div>

            <datalist id="admin-carriers">
                {carriers.map((carrier) => (
                    <option key={carrier.code} value={carrier.name} />
                ))}
            </datalist>

            <div className="mt-3">
                <Field label="Reason — kept in the parcel’s history" error={form.errors.reason}>
                    {({ id, describedBy, invalid }) => (
                        <Textarea
                            id={id}
                            aria-describedby={describedBy}
                            invalid={invalid}
                            value={form.data.reason}
                            onChange={(event) => form.setData('reason', event.target.value)}
                        />
                    )}
                </Field>
            </div>
        </Modal>
    );
}
