import { StatusBadge } from '../../design-system/primitives/StatusBadge';
import type { CustomerFulfilmentView, CustomerTrackingGroup } from '../../shared/commerce';

/**
 * Each seller's part of the order, kept visually apart.
 *
 * A customer who bought from three sellers is waiting on three deliveries
 * that will arrive at three different times from three different couriers.
 * Merging that into one progress bar would be tidier and wrong: it would
 * say "shipped" when a third of the order had left, and the customer would
 * find out at the door.
 *
 * The parent summary above the groups is derived on the server, from the
 * least advanced seller, so this page and the order list cannot disagree.
 */
export function TrackingGroups({ fulfilment }: { fulfilment: CustomerFulfilmentView }) {
    return (
        <section aria-labelledby="tracking-heading" className="mb-10">
            <h2 id="tracking-heading" className="mb-3 text-[22px]">
                Where your order is
            </h2>

            <div role="status" className="mb-6 border-2 border-[var(--vc-text)] px-5 py-4">
                <p className="text-[16px] font-semibold">{fulfilment.summary.label}</p>
                <p className="mt-1 text-[14px] text-[var(--vc-neutral-700)]">
                    {fulfilment.summary.detail}
                </p>
            </div>

            <ul>
                {fulfilment.groups.map((group) => (
                    <TrackingGroup key={group.reference} group={group} />
                ))}
            </ul>
        </section>
    );
}

function TrackingGroup({ group }: { group: CustomerTrackingGroup }) {
    return (
        <li className="mb-6 border-2 border-[var(--vc-divider)] p-5">
            <div className="mb-3 flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <h3 className="text-[16px]">{group.storeName}</h3>
                <span className="vc-tabular text-[12px] text-[var(--vc-neutral-600)]">
                    {group.reference}
                </span>
                <StatusBadge domain="seller_order" value={group.status} />
            </div>

            {group.shipments.length === 0 ? (
                <p className="text-[14px] text-[var(--vc-neutral-700)]">
                    {group.confirmedAt
                        ? 'This seller is preparing your items.'
                        : 'This seller has not started on your items yet.'}
                </p>
            ) : (
                <ul className="border-t border-[var(--vc-divider)]">
                    {group.shipments.map((shipment) => (
                        <li
                            key={shipment.reference}
                            className="border-b border-[var(--vc-divider)] py-3 text-[14px]"
                        >
                            <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                                <StatusBadge domain="shipment" value={shipment.status} />
                                {shipment.carrierName ? (
                                    <span>
                                        {shipment.carrierName}
                                        {shipment.trackingNumber ? (
                                            <>
                                                {' · '}
                                                {shipment.trackingUrl ? (
                                                    <a
                                                        href={shipment.trackingUrl}
                                                        target="_blank"
                                                        rel="noreferrer noopener"
                                                        className="vc-tabular underline underline-offset-4"
                                                    >
                                                        {shipment.trackingNumber}
                                                    </a>
                                                ) : (
                                                    <span className="vc-tabular">
                                                        {shipment.trackingNumber}
                                                    </span>
                                                )}
                                            </>
                                        ) : null}
                                    </span>
                                ) : null}
                            </div>

                            <ul className="mt-1 text-[13px] text-[var(--vc-neutral-700)]">
                                {shipment.items.map((item) => (
                                    <li
                                        key={`${shipment.reference}-${item.title}-${item.quantity}`}
                                    >
                                        {item.quantity} × {item.title}
                                        {item.variantName ? ` — ${item.variantName}` : ''}
                                    </li>
                                ))}
                            </ul>

                            {shipment.deliveredAt ? (
                                <p className="mt-1 text-[12px] text-[var(--vc-neutral-600)]">
                                    Delivered{' '}
                                    <time dateTime={shipment.deliveredAt}>
                                        {new Date(shipment.deliveredAt).toLocaleString()}
                                    </time>
                                </p>
                            ) : shipment.shippedAt ? (
                                <p className="mt-1 text-[12px] text-[var(--vc-neutral-600)]">
                                    Sent{' '}
                                    <time dateTime={shipment.shippedAt}>
                                        {new Date(shipment.shippedAt).toLocaleString()}
                                    </time>
                                </p>
                            ) : null}
                        </li>
                    ))}
                </ul>
            )}
        </li>
    );
}
