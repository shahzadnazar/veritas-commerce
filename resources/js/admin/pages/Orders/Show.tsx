import { Link, usePage } from '@inertiajs/react';
import { AddressBlock, MoneyRow, OrderTotals } from '../../../design-system/patterns/OrderPieces';
import { StatusBadge } from '../../../design-system/primitives/StatusBadge';
import type { MarketplaceOrderView } from '../../../shared/commerce';
import type { SharedPageProps } from '../../../shared/types';
import { AdminLayout } from '../../layouts/AdminLayout';

interface ShowProps extends SharedPageProps {
    order: MarketplaceOrderView;
    canSeeFinance: boolean;
    checkout: {
        publicId: string;
        status: string;
        expiresAt: string | null;
        completedAt: string | null;
        failureReason: string | null;
        reservationReference: string;
    } | null;
    reservations: {
        offerId: number;
        quantity: number;
        status: string;
        expiresAt: string | null;
        resolvedAt: string | null;
    }[];
    payments: {
        status: string;
        provider: string;
        reference: string | null;
        createdAt: string | null;
    }[];
    history: {
        scope: string;
        from: string | null;
        to: string;
        actorType: string;
        note: string | null;
        at: string;
    }[];
}

/**
 * The whole hierarchy of one order, for the people who have to answer for
 * it.
 *
 * The only screen that legitimately reads across every seller on an order.
 * Finance columns are a second permission, not a consequence of being
 * staff — support can answer "where is the parcel" without seeing what
 * the platform took from each seller.
 *
 * Everything shown is a snapshot or a recorded fact. Nothing is
 * recalculated here, so an admin looking at a two-year-old order sees what
 * the customer saw on the day.
 */
export default function Show() {
    const { order, canSeeFinance, checkout, reservations, payments, history } =
        usePage<ShowProps>().props;

    return (
        <AdminLayout title={order.reference}>
            <p className="mb-6 text-[13px]">
                <Link href="/admin/orders" className="underline underline-offset-4">
                    All orders
                </Link>
            </p>

            <div className="mb-8 flex flex-wrap items-baseline gap-x-4 gap-y-2">
                <h2 className="text-[24px]">{order.reference}</h2>
                <StatusBadge domain="marketplace_order" value={order.status} />
                <span className="text-[12px] text-[var(--vc-neutral-600)]">
                    {order.sellerOrders.length}{' '}
                    {order.sellerOrders.length === 1 ? 'seller order' : 'seller orders'}
                </span>
            </div>

            <div className="grid gap-10 lg:grid-cols-[1fr_300px]">
                <div className="flex flex-col gap-10">
                    <section aria-labelledby="hierarchy-heading">
                        <h3 id="hierarchy-heading" className="mb-3 text-[18px]">
                            Seller orders
                        </h3>

                        {order.sellerOrders.map((sellerOrder) => (
                            <article
                                key={sellerOrder.reference}
                                className="mb-6 border-2 border-[var(--vc-divider)] p-4"
                            >
                                <div className="mb-3 flex flex-wrap items-baseline gap-x-3 gap-y-1">
                                    <h4 className="text-[15px] font-semibold">
                                        {sellerOrder.reference}
                                    </h4>
                                    <span className="text-[12px] text-[var(--vc-neutral-600)]">
                                        {sellerOrder.storeName ?? '—'}
                                    </span>
                                    <StatusBadge domain="seller_order" value={sellerOrder.status} />
                                </div>

                                <ul className="border-t border-[var(--vc-divider)]">
                                    {sellerOrder.items.map((item) => (
                                        <li
                                            key={item.publicId}
                                            className="flex flex-col gap-1 border-b border-[var(--vc-divider)] py-2 text-[13px] sm:flex-row sm:justify-between sm:gap-4"
                                        >
                                            <span>
                                                <span className="font-semibold">
                                                    {item.productTitle}
                                                </span>
                                                {item.variantName ? ` — ${item.variantName}` : ''}
                                                <span className="block text-[12px] text-[var(--vc-neutral-600)]">
                                                    SKU {item.sellerSku} · {item.quantity} ×{' '}
                                                    {item.unitPrice.formatted}
                                                    {item.brand ? ` · ${item.brand}` : ''}
                                                </span>
                                            </span>

                                            <span className="vc-tabular whitespace-nowrap sm:text-right">
                                                {item.lineTotal.formatted}
                                                {canSeeFinance && item.commission ? (
                                                    <span className="block text-[12px] text-[var(--vc-neutral-600)]">
                                                        commission {item.commission.formatted} @{' '}
                                                        {item.commissionRate}%
                                                    </span>
                                                ) : null}
                                            </span>
                                        </li>
                                    ))}
                                </ul>

                                <div className="mt-3 text-[13px]">
                                    <MoneyRow
                                        label="Items"
                                        value={sellerOrder.itemsTotal.formatted}
                                    />
                                    <MoneyRow
                                        label="Delivery"
                                        value={sellerOrder.shippingTotal.formatted}
                                    />
                                    <MoneyRow
                                        label="Seller order total"
                                        value={sellerOrder.orderTotal.formatted}
                                    />
                                    {canSeeFinance &&
                                    sellerOrder.commissionTotal &&
                                    sellerOrder.sellerEarningTotal ? (
                                        <>
                                            <MoneyRow
                                                label="Commission"
                                                value={sellerOrder.commissionTotal.formatted}
                                            />
                                            <MoneyRow
                                                label="Seller earning"
                                                value={sellerOrder.sellerEarningTotal.formatted}
                                            />
                                        </>
                                    ) : null}
                                </div>
                            </article>
                        ))}
                    </section>

                    <section aria-labelledby="foundation-heading">
                        <h3 id="foundation-heading" className="mb-3 text-[18px]">
                            Checkout, holds and payment
                        </h3>

                        <dl className="grid gap-2 text-[13px] sm:grid-cols-2">
                            <div>
                                <dt className="text-[var(--vc-neutral-600)]">Checkout attempt</dt>
                                <dd>
                                    {checkout ? (
                                        <>
                                            <StatusBadge
                                                domain="checkout"
                                                value={checkout.status}
                                            />
                                            <span className="ml-2 vc-tabular">
                                                {checkout.reservationReference}
                                            </span>
                                        </>
                                    ) : (
                                        '—'
                                    )}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-[var(--vc-neutral-600)]">Payment attempts</dt>
                                <dd>
                                    {payments.length === 0
                                        ? 'None recorded'
                                        : payments.map((payment, index) => (
                                              <span key={index} className="mr-2">
                                                  <StatusBadge
                                                      domain="payment"
                                                      value={payment.status}
                                                  />
                                              </span>
                                          ))}
                                </dd>
                            </div>
                        </dl>

                        <h4 className="mt-4 mb-2 text-[13px] font-semibold">Inventory holds</h4>
                        {reservations.length === 0 ? (
                            <p className="text-[13px] text-[var(--vc-neutral-600)]">
                                No holds recorded for this order.
                            </p>
                        ) : (
                            <ul className="text-[13px]">
                                {reservations.map((reservation, index) => (
                                    <li
                                        key={index}
                                        className="flex justify-between border-b border-[var(--vc-divider)] py-1"
                                    >
                                        <span>
                                            Offer #{reservation.offerId} × {reservation.quantity}
                                        </span>
                                        <StatusBadge
                                            domain="inventory_reservation"
                                            value={reservation.status}
                                        />
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>

                    <section aria-labelledby="history-heading">
                        <h3 id="history-heading" className="mb-3 text-[18px]">
                            State history
                        </h3>

                        <ol className="text-[13px]">
                            {history.map((row, index) => (
                                <li
                                    key={index}
                                    className="flex flex-col gap-1 border-b border-[var(--vc-divider)] py-2 sm:flex-row sm:justify-between"
                                >
                                    <span>
                                        <span className="vc-tabular font-semibold">
                                            {row.scope}
                                        </span>{' '}
                                        {row.from ? `${row.from} → ` : ''}
                                        {row.to}
                                        {row.note ? (
                                            <span className="block text-[12px] text-[var(--vc-neutral-600)]">
                                                {row.note}
                                            </span>
                                        ) : null}
                                    </span>
                                    <span className="text-[12px] text-[var(--vc-neutral-600)]">
                                        {row.actorType} ·{' '}
                                        <time dateTime={row.at}>
                                            {new Date(row.at).toLocaleString()}
                                        </time>
                                    </span>
                                </li>
                            ))}
                        </ol>
                    </section>
                </div>

                <aside className="flex flex-col gap-8">
                    <div>
                        <h3 className="mb-2 text-[16px]">Total</h3>
                        <OrderTotals
                            itemsTotal={order.itemsTotal}
                            shippingTotal={order.shippingTotal}
                            taxTotal={order.taxTotal}
                            grandTotal={order.grandTotal}
                        />
                        <p className="mt-2 text-[12px] text-[var(--vc-neutral-600)]">
                            Currency {order.currency}
                        </p>
                    </div>

                    <div>
                        <h3 className="mb-2 text-[11px] font-semibold tracking-[0.08em] text-[var(--vc-neutral-600)] uppercase">
                            Customer
                        </h3>
                        <p className="text-[14px]">{order.email}</p>
                    </div>

                    <AddressBlock address={order.shippingAddress} title="Shipping snapshot" />

                    {!canSeeFinance ? (
                        <p className="text-[12px] text-[var(--vc-neutral-600)]">
                            Commission and seller earnings are hidden for your role.
                        </p>
                    ) : null}
                </aside>
            </div>
        </AdminLayout>
    );
}
