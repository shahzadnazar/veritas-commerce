import { Link, usePage } from '@inertiajs/react';
import { StorefrontLayout } from '../../../../design-system/layout/StorefrontLayout';
import { AddressBlock, OrderTotals } from '../../../../design-system/patterns/OrderPieces';
import { StatusBadge } from '../../../../design-system/primitives/StatusBadge';
import type { MarketplaceOrderView } from '../../../../shared/commerce';
import type { SharedPageProps } from '../../../../shared/types';

interface OrderShowProps extends SharedPageProps {
    order: MarketplaceOrderView;
}

/**
 * One order, entirely from its own snapshots.
 *
 * Every title, price, store name and address on this page is the copy the
 * order took when it was placed — not a lookup against the catalogue as it
 * is today. A seller who renames their shop or reprices a listing next
 * month must not be able to change what this receipt says, and the way to
 * be certain of that is for the page to have no route to those tables.
 *
 * The parent/child shape is shown rather than flattened: a customer who
 * bought from three sellers has three parcels arriving at three times, and
 * a single merged list would mislead them about that.
 */
export default function OrderShow() {
    const { order } = usePage<OrderShowProps>().props;

    return (
        <StorefrontLayout title={`Order ${order.reference}`}>
            <p className="mb-2 text-[13px]">
                <Link href="/account/orders" className="underline underline-offset-4">
                    All orders
                </Link>
            </p>

            <div className="mb-8 flex flex-wrap items-baseline gap-x-4 gap-y-2">
                <h1 className="text-[42px]">{order.reference}</h1>
                <StatusBadge domain="marketplace_order" value={order.status} />
            </div>

            <p className="mb-10 text-[14px] text-[var(--vc-neutral-700)]">
                {order.placedAt ? (
                    <>
                        Placed{' '}
                        <time dateTime={order.placedAt}>
                            {new Date(order.placedAt).toLocaleString()}
                        </time>
                    </>
                ) : (
                    'Not yet placed'
                )}
                {' · '}
                {order.sellerOrders.length} {order.sellerOrders.length === 1 ? 'seller' : 'sellers'}
            </p>

            <div className="grid gap-14 lg:grid-cols-[1fr_320px]">
                <div>
                    <h2 className="mb-4 text-[22px]">Items</h2>

                    {order.sellerOrders.map((sellerOrder) => (
                        <section
                            key={sellerOrder.reference}
                            aria-labelledby={`so-${sellerOrder.reference}`}
                            className="mb-10"
                        >
                            <div className="mb-2 flex flex-wrap items-baseline gap-x-3 gap-y-1">
                                <h3 id={`so-${sellerOrder.reference}`} className="text-[18px]">
                                    {sellerOrder.storeName ?? 'Seller'}
                                </h3>
                                <span className="vc-tabular text-[12px] text-[var(--vc-neutral-600)]">
                                    {sellerOrder.reference}
                                </span>
                                <StatusBadge domain="seller_order" value={sellerOrder.status} />
                            </div>

                            <ul className="border-t border-[var(--vc-divider)]">
                                {sellerOrder.items.map((item) => (
                                    <li
                                        key={item.publicId}
                                        className="flex flex-col gap-1 border-b border-[var(--vc-divider)] py-3 text-[14px] sm:flex-row sm:justify-between sm:gap-4"
                                    >
                                        <span>
                                            {item.brand ? (
                                                <span className="block text-[11px] tracking-[0.06em] text-[var(--vc-neutral-600)] uppercase">
                                                    {item.brand}
                                                </span>
                                            ) : null}
                                            {/*
                                             * The title as it was, linked
                                             * to the slug as it was. If
                                             * the product has since moved
                                             * the link 404s, which is
                                             * correct: the receipt still
                                             * says what was bought.
                                             */}
                                            {item.productSlug ? (
                                                <Link href={`/products/${item.productSlug}`}>
                                                    {item.productTitle}
                                                </Link>
                                            ) : (
                                                item.productTitle
                                            )}
                                            {item.variantName ? (
                                                <span className="block text-[13px] text-[var(--vc-neutral-700)]">
                                                    {item.variantName}
                                                </span>
                                            ) : null}
                                            <span className="block text-[12px] text-[var(--vc-neutral-600)]">
                                                {item.quantity} × {item.unitPrice.formatted}
                                            </span>
                                        </span>
                                        <span className="vc-tabular whitespace-nowrap sm:text-right">
                                            {item.lineTotal.formatted}
                                        </span>
                                    </li>
                                ))}
                            </ul>

                            <p className="pt-2 text-right text-[13px] vc-tabular">
                                Seller subtotal {sellerOrder.itemsTotal.formatted}
                            </p>
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
                            taxNote="Tax was not calculated for this order."
                        />
                    </div>

                    {/*
                     * The address the order was sent to, not the one in
                     * the address book today. Editing a saved address
                     * must never rewrite where a placed order went.
                     */}
                    <AddressBlock address={order.shippingAddress} title="Delivered to" />

                    <div>
                        <h3 className="mb-2 text-[11px] font-semibold tracking-[0.08em] text-[var(--vc-neutral-600)] uppercase">
                            Payment
                        </h3>
                        <StatusBadge domain="marketplace_order" value={order.status} />
                    </div>
                </aside>
            </div>
        </StorefrontLayout>
    );
}
