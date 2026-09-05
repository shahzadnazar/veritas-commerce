import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { EmptyState } from '../../design-system/patterns/States';
import { Button } from '../../design-system/primitives/Button';
import { Field, Input, Select, Textarea } from '../../design-system/primitives/Field';
import { StatusBadge } from '../../design-system/primitives/StatusBadge';
import type { SellerFulfilmentView, ShipmentView } from '../../shared/commerce';

/**
 * Everything a seller does to get an order out of the door.
 *
 * The panel offers what the server says is possible and nothing more —
 * `canConfirm`, `canPack`, `remainingToShip` all arrive computed, and no
 * quantity here is worked out in the browser. That is not defensive
 * duplication avoided for its own sake: an order's remaining quantity
 * depends on refunds in another module, and a number recalculated in React
 * would be a fourth opinion about what the seller owes.
 *
 * It is also honest about what Phase 1 knows. There is no carrier
 * integration, so "Mark delivered" says who is recording it rather than
 * implying the marketplace watched the parcel arrive.
 */
export function FulfilmentPanel({
    fulfilment,
    reference,
}: {
    fulfilment: SellerFulfilmentView;
    reference: string;
}) {
    const base = `/seller/orders/${reference}`;
    const [packing, setPacking] = useState(false);

    if (!fulfilment.actionable) {
        return (
            <section
                role="status"
                className="mb-8 border-2 border-[var(--vc-neutral-400)] px-5 py-4"
            >
                <h2 className="text-[16px] font-semibold">Not ready to fulfil</h2>
                <p className="mt-1 text-[14px] text-[var(--vc-neutral-700)]">
                    {fulfilment.reason ?? 'This order cannot be worked on.'}
                </p>
            </section>
        );
    }

    return (
        <section aria-labelledby="fulfilment-heading" className="mb-10">
            <div className="mb-4 flex flex-wrap items-baseline justify-between gap-3">
                <h2 id="fulfilment-heading" className="text-[20px]">
                    Fulfilment
                </h2>

                {fulfilment.canManage ? (
                    <div className="flex flex-wrap gap-2">
                        {fulfilment.canConfirm ? (
                            <Button
                                variant="primary"
                                onClick={() =>
                                    router.post(`${base}/confirm`, {}, { preserveScroll: true })
                                }
                            >
                                Confirm order
                            </Button>
                        ) : null}

                        {fulfilment.canProcess ? (
                            <Button
                                onClick={() =>
                                    router.post(`${base}/process`, {}, { preserveScroll: true })
                                }
                            >
                                Start preparing
                            </Button>
                        ) : null}

                        {fulfilment.canPack && fulfilment.remainingUnits > 0 ? (
                            <Button variant="primary" onClick={() => setPacking((open) => !open)}>
                                {packing ? 'Close packing list' : 'Create shipment'}
                            </Button>
                        ) : null}
                    </div>
                ) : null}
            </div>

            <ItemProgress fulfilment={fulfilment} />

            {packing && fulfilment.canManage ? (
                <PackingForm fulfilment={fulfilment} base={base} onDone={() => setPacking(false)} />
            ) : null}

            <Shipments fulfilment={fulfilment} base={base} />

            {fulfilment.canManage ? <IssueForm base={base} /> : null}

            {fulfilment.issues.length > 0 ? (
                <div className="mt-8">
                    <h3 className="mb-2 text-[16px]">Reported problems</h3>
                    <ul className="border-t border-[var(--vc-divider)]">
                        {fulfilment.issues.map((issue) => (
                            <li
                                key={issue.publicId}
                                className="border-b border-[var(--vc-divider)] py-3 text-[14px]"
                            >
                                <span className="flex flex-wrap items-baseline gap-3">
                                    <StatusBadge domain="fulfilment_issue" value={issue.reason} />
                                    <span className="text-[12px] text-[var(--vc-neutral-600)]">
                                        {issue.resolvedAt
                                            ? 'Resolved'
                                            : 'Awaiting the marketplace team'}
                                    </span>
                                </span>
                                <p className="mt-1 text-[var(--vc-neutral-700)]">{issue.note}</p>
                            </li>
                        ))}
                    </ul>
                </div>
            ) : null}
        </section>
    );
}

/** What is left to send, per line, from the server's own arithmetic. */
function ItemProgress({ fulfilment }: { fulfilment: SellerFulfilmentView }) {
    return (
        <ul className="mb-6 border-t border-[var(--vc-divider)]">
            {fulfilment.items.map((item) => (
                <li
                    key={item.orderItemId}
                    className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 border-b border-[var(--vc-divider)] py-3 text-[14px]"
                >
                    <span>
                        {item.title}
                        {item.variantName ? ` — ${item.variantName}` : ''}
                        <span className="block text-[12px] text-[var(--vc-neutral-600)]">
                            {item.sku}
                        </span>
                    </span>

                    <span className="vc-tabular text-[13px] whitespace-nowrap">
                        {item.delivered} delivered · {item.shipped} sent · {item.remainingToShip} to
                        send
                        {item.refunded > 0 ? ` · ${item.refunded} refunded` : ''}
                        <span className="block text-[12px] text-[var(--vc-neutral-600)]">
                            of {item.ordered} ordered
                        </span>
                    </span>
                </li>
            ))}
        </ul>
    );
}

/**
 * The packing list.
 *
 * Quantities default to everything still owed, because that is what one
 * shipment usually is — and the field stays editable, because the whole
 * reason shipments are their own aggregate is that sometimes it is not.
 */
function PackingForm({
    fulfilment,
    base,
    onDone,
}: {
    fulfilment: SellerFulfilmentView;
    base: string;
    onDone: () => void;
}) {
    const shippable = fulfilment.items.filter((item) => item.remainingToShip > 0);

    const form = useForm<{
        lines: { order_item_id: number; quantity: number }[];
        carrier: string;
        tracking_number: string;
        notes: string;
    }>({
        lines: shippable.map((item) => ({
            order_item_id: item.orderItemId,
            quantity: item.remainingToShip,
        })),
        carrier: '',
        tracking_number: '',
        notes: '',
    });

    const quantityFor = (id: number) =>
        form.data.lines.find((line) => line.order_item_id === id)?.quantity ?? 0;

    const setQuantity = (id: number, quantity: number, max: number) => {
        const clamped = Math.max(0, Math.min(quantity, max));
        const others = form.data.lines.filter((line) => line.order_item_id !== id);

        form.setData(
            'lines',
            clamped === 0 ? others : [...others, { order_item_id: id, quantity: clamped }],
        );
    };

    const total = form.data.lines.reduce((sum, line) => sum + line.quantity, 0);

    return (
        <form
            className="mb-8 border-2 border-[var(--vc-text)] p-5"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(`${base}/shipments`, {
                    preserveScroll: true,
                    onSuccess: () => {
                        form.reset();
                        onDone();
                    },
                });
            }}
        >
            <h3 className="mb-4 text-[16px]">What is in this parcel</h3>

            <ul className="mb-5 border-t border-[var(--vc-divider)]">
                {shippable.map((item) => (
                    <li
                        key={item.orderItemId}
                        className="flex flex-wrap items-end justify-between gap-3 border-b border-[var(--vc-divider)] py-3"
                    >
                        <span className="text-[14px]">
                            {item.title}
                            <span className="block text-[12px] text-[var(--vc-neutral-600)]">
                                up to {item.remainingToShip}
                            </span>
                        </span>

                        <Field label={`Quantity of ${item.title}`}>
                            {({ id }) => (
                                <Input
                                    id={id}
                                    type="number"
                                    min={0}
                                    max={item.remainingToShip}
                                    className="w-24"
                                    value={quantityFor(item.orderItemId)}
                                    onChange={(event) =>
                                        setQuantity(
                                            item.orderItemId,
                                            Number(event.target.value || 0),
                                            item.remainingToShip,
                                        )
                                    }
                                />
                            )}
                        </Field>
                    </li>
                ))}
            </ul>

            <div className="mb-5 grid gap-3 sm:grid-cols-2">
                <Field
                    label="Carrier"
                    hint="Choose one we can build a tracking link for, or type any other."
                    error={form.errors.carrier}
                >
                    {({ id, describedBy, invalid }) => (
                        <Input
                            id={id}
                            list="known-carriers"
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

            <datalist id="known-carriers">
                {fulfilment.carriers.map((carrier) => (
                    <option key={carrier.code} value={carrier.name} />
                ))}
            </datalist>

            <Field label="Note for your own records" error={form.errors.notes}>
                {({ id, describedBy, invalid }) => (
                    <Textarea
                        id={id}
                        aria-describedby={describedBy}
                        invalid={invalid}
                        value={form.data.notes}
                        onChange={(event) => form.setData('notes', event.target.value)}
                    />
                )}
            </Field>

            <div className="mt-5 flex flex-wrap gap-2">
                <Button
                    type="submit"
                    variant="primary"
                    loading={form.processing}
                    loadingLabel="Creating…"
                    disabled={total === 0}
                >
                    {`Create shipment of ${total} ${total === 1 ? 'item' : 'items'}`}
                </Button>
                <Button variant="ghost" type="button" onClick={onDone}>
                    Cancel
                </Button>
            </div>
        </form>
    );
}

function Shipments({ fulfilment, base }: { fulfilment: SellerFulfilmentView; base: string }) {
    if (fulfilment.shipments.length === 0) {
        return (
            <EmptyState
                title="Nothing has been packed yet"
                body="Create a shipment when the parcel is ready to hand over."
            />
        );
    }

    return (
        <div>
            <h3 className="mb-3 text-[16px]">Parcels</h3>

            <ul className="border-t-2 border-[var(--vc-text)]">
                {fulfilment.shipments.map((shipment) => (
                    <ShipmentRow
                        key={shipment.publicId}
                        shipment={shipment}
                        base={base}
                        canManage={fulfilment.canManage}
                        carriers={fulfilment.carriers}
                    />
                ))}
            </ul>
        </div>
    );
}

function ShipmentRow({
    shipment,
    base,
    canManage,
    carriers,
}: {
    shipment: ShipmentView;
    base: string;
    canManage: boolean;
    carriers: { code: string; name: string }[];
}) {
    const [editing, setEditing] = useState(false);
    const parcel = `${base}/shipments/${shipment.publicId}`;

    const tracking = useForm({
        carrier: shipment.carrierName ?? '',
        tracking_number: shipment.trackingNumber ?? '',
    });

    return (
        <li className="border-b border-[var(--vc-divider)] py-4">
            <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-2">
                <span className="flex flex-wrap items-baseline gap-3">
                    <span className="vc-tabular font-semibold">{shipment.reference}</span>
                    <StatusBadge domain="shipment" value={shipment.status} />
                </span>

                {canManage ? (
                    <span className="flex flex-wrap gap-2">
                        <Button variant="ghost" onClick={() => setEditing((open) => !open)}>
                            {editing ? 'Close' : 'Edit tracking'}
                        </Button>

                        {shipment.canShip ? (
                            <Button
                                variant="primary"
                                onClick={() =>
                                    router.post(`${parcel}/ship`, {}, { preserveScroll: true })
                                }
                            >
                                Mark sent
                            </Button>
                        ) : null}

                        {shipment.canDeliver ? (
                            <Button
                                onClick={() =>
                                    router.post(`${parcel}/deliver`, {}, { preserveScroll: true })
                                }
                            >
                                Mark delivered
                            </Button>
                        ) : null}
                    </span>
                ) : null}
            </div>

            <ul className="mt-2 text-[13px] text-[var(--vc-neutral-700)]">
                {shipment.items.map((item) => (
                    <li key={item.orderItemId}>
                        {item.quantity} × {item.title}
                        {item.variantName ? ` — ${item.variantName}` : ''}
                    </li>
                ))}
            </ul>

            <p className="mt-2 text-[13px]">
                {shipment.carrierName ? (
                    <>
                        {shipment.carrierName}
                        {shipment.trackingNumber ? (
                            <>
                                {' · '}
                                <span className="vc-tabular">{shipment.trackingNumber}</span>
                            </>
                        ) : null}
                    </>
                ) : (
                    <span className="text-[var(--vc-neutral-600)]">
                        No carrier yet — add one before marking this sent.
                    </span>
                )}
            </p>

            {shipment.deliveredAt ? (
                <p className="mt-1 text-[12px] text-[var(--vc-neutral-600)]">
                    Delivery recorded{' '}
                    <time dateTime={shipment.deliveredAt}>
                        {new Date(shipment.deliveredAt).toLocaleString()}
                    </time>{' '}
                    by a person — we have no carrier feed in this release.
                </p>
            ) : null}

            {editing && canManage ? (
                <form
                    className="mt-3 grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end"
                    onSubmit={(event) => {
                        event.preventDefault();
                        tracking.post(`${parcel}/tracking`, {
                            preserveScroll: true,
                            onSuccess: () => setEditing(false),
                        });
                    }}
                >
                    <Field label="Carrier">
                        {({ id }) => (
                            <Input
                                id={id}
                                list={`carriers-${shipment.publicId}`}
                                value={tracking.data.carrier}
                                onChange={(event) =>
                                    tracking.setData('carrier', event.target.value)
                                }
                            />
                        )}
                    </Field>

                    <Field label="Tracking number">
                        {({ id }) => (
                            <Input
                                id={id}
                                value={tracking.data.tracking_number}
                                onChange={(event) =>
                                    tracking.setData('tracking_number', event.target.value)
                                }
                            />
                        )}
                    </Field>

                    <datalist id={`carriers-${shipment.publicId}`}>
                        {carriers.map((carrier) => (
                            <option key={carrier.code} value={carrier.name} />
                        ))}
                    </datalist>

                    <Button type="submit" loading={tracking.processing} loadingLabel="Saving…">
                        Save tracking
                    </Button>
                </form>
            ) : null}

            {shipment.history && shipment.history.length > 0 ? (
                <details className="mt-2">
                    <summary className="cursor-pointer text-[12px] text-[var(--vc-neutral-600)]">
                        History
                    </summary>
                    <ol className="mt-2 text-[12px] text-[var(--vc-neutral-700)]">
                        {shipment.history.map((entry, index) => (
                            <li key={`${entry.at}-${index}`}>
                                <time dateTime={entry.at}>
                                    {new Date(entry.at).toLocaleString()}
                                </time>
                                {' — '}
                                {entry.from && entry.from !== entry.to
                                    ? `${entry.from} → ${entry.to}`
                                    : entry.to}
                                {entry.trackingNumber ? ` · ${entry.trackingNumber}` : ''}
                                {entry.reason ? ` · ${entry.reason}` : ''}
                            </li>
                        ))}
                    </ol>
                </details>
            ) : null}
        </li>
    );
}

/** Raising a hand, which is not the same as reaching for a refund. */
function IssueForm({ base }: { base: string }) {
    const [open, setOpen] = useState(false);

    const form = useForm({ reason: 'out_of_stock_after_sale', note: '' });

    if (!open) {
        return (
            <p className="mt-8 text-[13px]">
                <button
                    type="button"
                    className="underline underline-offset-4"
                    onClick={() => setOpen(true)}
                >
                    Report a problem with this order
                </button>
            </p>
        );
    }

    return (
        <form
            className="mt-8 border-2 border-[var(--vc-accent)] p-5"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(`${base}/issues`, {
                    preserveScroll: true,
                    onSuccess: () => {
                        form.reset();
                        setOpen(false);
                    },
                });
            }}
        >
            <h3 className="mb-1 text-[16px]">Report a problem</h3>
            <p className="mb-4 text-[13px] text-[var(--vc-neutral-700)]">
                The marketplace team will pick this up and decide what happens to the
                customer&rsquo;s money. You cannot refund an order yourself.
            </p>

            <div className="grid gap-3 sm:grid-cols-2">
                <Field label="What went wrong" error={form.errors.reason}>
                    {({ id }) => (
                        <Select
                            id={id}
                            value={form.data.reason}
                            onChange={(event) => form.setData('reason', event.target.value)}
                        >
                            <option value="out_of_stock_after_sale">Out of stock after sale</option>
                            <option value="damaged_before_shipment">Damaged before shipment</option>
                            <option value="address_problem">Address problem</option>
                            <option value="carrier_problem">Carrier problem</option>
                            <option value="other">Other</option>
                        </Select>
                    )}
                </Field>
            </div>

            <div className="mt-3">
                <Field
                    label="What happened"
                    error={form.errors.note}
                    hint="Enough for somebody to act on."
                >
                    {({ id, describedBy, invalid }) => (
                        <Textarea
                            id={id}
                            aria-describedby={describedBy}
                            invalid={invalid}
                            value={form.data.note}
                            onChange={(event) => form.setData('note', event.target.value)}
                        />
                    )}
                </Field>
            </div>

            <div className="mt-4 flex gap-2">
                <Button
                    type="submit"
                    variant="primary"
                    loading={form.processing}
                    loadingLabel="Sending…"
                >
                    Report it
                </Button>
                <Button variant="ghost" type="button" onClick={() => setOpen(false)}>
                    Cancel
                </Button>
            </div>
        </form>
    );
}
