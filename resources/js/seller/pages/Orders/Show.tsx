import { Link, usePage } from '@inertiajs/react';
import { AddressBlock, MoneyRow } from '../../../design-system/patterns/OrderPieces';
import { StatusBadge } from '../../../design-system/primitives/StatusBadge';
import type { AddressSnapshot, SellerOrderView } from '../../../shared/commerce';
import type { SharedPageProps } from '../../../shared/types';
import { SellerLayout } from '../../layouts/SellerLayout';

interface ShowProps extends SharedPageProps {
    sellerOrder: SellerOrderView;
    parent: {
        reference: string;
        status: string;
        placedAt: string | null;
        shippingAddress: AddressSnapshot;
    };
    fulfilment: { actionable: boolean; reason: string | null };
    canSeeFinance: boolean;
}

/**
 * One seller order — this seller's part, and nothing else.
 *
 * The page is given only what this seller needs to pack and post the
 * parcel. There is no path from here to the other sellers on the same
 * customer order, their items, their totals or their earnings, because
 * the controller never loads them.
 *
 * Money is shown from the item snapshots taken at purchase. A price
 * changed since then does not change what this order is worth.
 */
export default function Show() {
    const { sellerOrder, parent, fulfilment, canSeeFinance } = usePage<ShowProps>().props;

    return (
        <SellerLayout title={sellerOrder.reference}>
            <p className="mb-6 text-[13px]">
                <Link href="/seller/orders" className="underline underline-offset-4">
                    All orders
                </Link>
            </p>

            <div className="mb-6 flex flex-wrap items-baseline gap-x-4 gap-y-2">
                <h2 className="text-[24px]">{sellerOrder.reference}</h2>
                <StatusBadge domain="seller_order" value={sellerOrder.status} />
                <span className="text-[12px] text-[var(--vc-neutral-600)]">
                    Part of customer order {parent.reference}
                </span>
            </div>

            {/*
             * §14's policy, and the whole of it: an unpaid order is
             * visible so a seller can see it coming, and is not
             * actionable. Saying so plainly is better than a screen with
             * greyed-out buttons and no explanation.
             */}
            {!fulfilment.actionable ? (
                <div
                    role="status"
                    className="mb-8 border-2 border-[var(--vc-neutral-400)] px-5 py-4 text-[14px]"
                >
                    <p className="font-semibold">Awaiting payment</p>
                    <p className="text-[var(--vc-neutral-700)]">{fulfilment.reason}</p>
                </div>
            ) : null}

            <div className="grid gap-10 lg:grid-cols-[1fr_300px]">
                <section aria-labelledby="items-heading">
                    <h3 id="items-heading" className="mb-3 text-[18px]">
                        Items to send
                    </h3>

                    <ul className="border-t-2 border-[var(--vc-text)]">
                        {sellerOrder.items.map((item) => (
                            <li
                                key={item.publicId}
                                className="flex flex-col gap-2 border-b border-[var(--vc-divider)] py-3 text-[13px] sm:flex-row sm:justify-between sm:gap-4"
                            >
                                <span>
                                    <span className="block font-semibold">{item.productTitle}</span>
                                    {item.variantName ? (
                                        <span className="block text-[var(--vc-neutral-700)]">
                                            {item.variantName}
                                        </span>
                                    ) : null}
                                    <span className="block text-[12px] text-[var(--vc-neutral-600)]">
                                        SKU {item.sellerSku} · {item.quantity} ×{' '}
                                        {item.unitPrice.formatted}
                                    </span>
                                </span>

                                <span className="vc-tabular whitespace-nowrap sm:text-right">
                                    {item.lineTotal.formatted}
                                    {canSeeFinance && item.sellerEarning ? (
                                        <span className="block text-[12px] text-[var(--vc-neutral-600)]">
                                            You earn {item.sellerEarning.formatted}
                                        </span>
                                    ) : null}
                                </span>
                            </li>
                        ))}
                    </ul>
                </section>

                <aside className="flex flex-col gap-8">
                    <div>
                        <h3 className="mb-2 text-[16px]">Your total</h3>
                        <MoneyRow label="Items" value={sellerOrder.itemsTotal.formatted} />
                        <MoneyRow
                            label="Delivery"
                            value={
                                sellerOrder.shippingTotal.minor === 0
                                    ? 'Included'
                                    : sellerOrder.shippingTotal.formatted
                            }
                        />
                        <MoneyRow
                            label="Order total"
                            value={sellerOrder.orderTotal.formatted}
                            strong
                        />

                        {canSeeFinance &&
                        sellerOrder.commissionTotal &&
                        sellerOrder.sellerEarningTotal ? (
                            <div className="mt-4 border-t border-[var(--vc-divider)] pt-3">
                                <MoneyRow
                                    label="Platform commission"
                                    value={sellerOrder.commissionTotal.formatted}
                                    note="Snapshotted at purchase; a later rate change does not alter it."
                                />
                                <MoneyRow
                                    label="You earn"
                                    value={sellerOrder.sellerEarningTotal.formatted}
                                />
                            </div>
                        ) : null}
                    </div>

                    <AddressBlock address={parent.shippingAddress} title="Send to" />
                </aside>
            </div>
        </SellerLayout>
    );
}
