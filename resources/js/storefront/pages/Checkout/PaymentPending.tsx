import { Link, usePage } from '@inertiajs/react';
import { StorefrontLayout } from '../../../design-system/layout/StorefrontLayout';
import { AddressBlock, OrderTotals } from '../../../design-system/patterns/OrderPieces';
import { StatusBadge } from '../../../design-system/primitives/StatusBadge';
import type { MarketplaceOrderView } from '../../../shared/commerce';
import type { SharedPageProps } from '../../../shared/types';

interface PaymentPendingProps extends SharedPageProps {
    order: MarketplaceOrderView;
    paymentStatus: { state: string; headline: string; detail: string };
}

/**
 * The M4 handoff.
 *
 * Deliberately NOT a confirmation page. The order exists, the totals are
 * final and the stock is held — and no money has moved. Saying "thank you
 * for your order" here would be a claim the platform cannot support, and
 * the customer would find out it was untrue at the least convenient
 * possible moment.
 *
 * M5 attaches a provider to this boundary. Until then the page's only job
 * is to be exact about where the purchase stands.
 */
export default function PaymentPending() {
    const { order, paymentStatus } = usePage<PaymentPendingProps>().props;

    return (
        <StorefrontLayout title={`Order ${order.reference}`}>
            <p className="mb-2 text-[13px] tracking-[0.08em] text-[var(--vc-neutral-600)] uppercase">
                Order {order.reference}
            </p>
            <h1 className="mb-3 text-[42px]">Awaiting payment</h1>

            <div role="status" className="mb-10 border-2 border-[var(--vc-text)] px-5 py-4">
                <p className="text-[16px] font-semibold">{paymentStatus.headline}</p>
                <p className="mt-1 text-[14px] text-[var(--vc-neutral-700)]">
                    {paymentStatus.detail}
                </p>
                {order.paymentExpiresAt ? (
                    <p className="mt-2 text-[13px]">
                        Held until{' '}
                        <time dateTime={order.paymentExpiresAt} className="font-semibold">
                            {new Date(order.paymentExpiresAt).toLocaleString()}
                        </time>
                        .
                    </p>
                ) : null}
            </div>

            <div className="grid gap-14 lg:grid-cols-[1fr_320px]">
                <div>
                    <h2 className="mb-4 text-[22px]">What you ordered</h2>

                    {order.sellerOrders.map((sellerOrder) => (
                        <section key={sellerOrder.reference} className="mb-8">
                            <div className="mb-2 flex flex-wrap items-baseline gap-x-3 gap-y-1">
                                <h3 className="text-[16px]">{sellerOrder.storeName ?? 'Seller'}</h3>
                                <span className="vc-tabular text-[12px] text-[var(--vc-neutral-600)]">
                                    {sellerOrder.reference}
                                </span>
                                <StatusBadge domain="seller_order" value={sellerOrder.status} />
                            </div>

                            <ul className="border-t border-[var(--vc-divider)]">
                                {sellerOrder.items.map((item) => (
                                    <li
                                        key={item.publicId}
                                        className="flex justify-between gap-4 border-b border-[var(--vc-divider)] py-3 text-[14px]"
                                    >
                                        <span>
                                            {item.productTitle}
                                            {item.variantName ? ` — ${item.variantName}` : ''}
                                            <span className="block text-[12px] text-[var(--vc-neutral-600)]">
                                                {item.quantity} × {item.unitPrice.formatted}
                                            </span>
                                        </span>
                                        <span className="vc-tabular whitespace-nowrap">
                                            {item.lineTotal.formatted}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    ))}
                </div>

                <aside className="flex flex-col gap-8">
                    <div>
                        <h2 className="mb-3 text-[20px]">Total</h2>
                        <OrderTotals
                            itemsTotal={order.itemsTotal}
                            shippingTotal={order.shippingTotal}
                            taxTotal={order.taxTotal}
                            grandTotal={order.grandTotal}
                            taxNote="Tax is not calculated at this stage."
                        />
                    </div>

                    <AddressBlock address={order.shippingAddress} title="Delivering to" />

                    <p className="text-[13px] text-[var(--vc-neutral-700)]">
                        <Link href="/account/orders" className="underline underline-offset-4">
                            See all your orders
                        </Link>
                    </p>
                </aside>
            </div>
        </StorefrontLayout>
    );
}
