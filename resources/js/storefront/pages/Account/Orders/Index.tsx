import { Link, usePage } from '@inertiajs/react';
import { StorefrontLayout } from '../../../../design-system/layout/StorefrontLayout';
import { EmptyState } from '../../../../design-system/patterns/States';
import { Button } from '../../../../design-system/primitives/Button';
import { StatusBadge } from '../../../../design-system/primitives/StatusBadge';
import type { Paginated } from '../../../../shared/commerce';
import type { SharedPageProps } from '../../../../shared/types';

interface OrderRow {
    reference: string;
    placedAt: string | null;
    status: string;
    sellerOrderCount: number;
    grandTotal: string;
    grandTotalMinor: number;
}

interface OrdersIndexProps extends SharedPageProps {
    orders: Paginated<OrderRow>;
}

/**
 * A customer's orders.
 *
 * Cards rather than a table, at every width. A purchase history read on a
 * phone is the common case, and a financial table that scrolls sideways is
 * not a mobile design — it is a desktop table with a scrollbar bolted on.
 */
export default function OrdersIndex() {
    const { orders } = usePage<OrdersIndexProps>().props;

    return (
        <StorefrontLayout title="Your orders">
            <h1 className="mb-8 text-[42px]">Your orders</h1>

            {orders.data.length === 0 ? (
                <EmptyState
                    title="No orders yet"
                    body="When you buy something, it will appear here with everything you need to track it."
                    actions={
                        <Link href="/search">
                            <Button variant="primary">Browse the marketplace</Button>
                        </Link>
                    }
                />
            ) : (
                <ul className="border-t-2 border-[var(--vc-text)]">
                    {orders.data.map((order) => (
                        <li
                            key={order.reference}
                            className="flex flex-col gap-3 border-b border-[var(--vc-divider)] py-5 sm:flex-row sm:items-center sm:justify-between"
                        >
                            <div>
                                <h2 className="text-[18px]">
                                    <Link href={`/account/orders/${order.reference}`}>
                                        {order.reference}
                                    </Link>
                                </h2>
                                <p className="text-[13px] text-[var(--vc-neutral-700)]">
                                    {order.placedAt ? (
                                        <time dateTime={order.placedAt}>
                                            {new Date(order.placedAt).toLocaleDateString()}
                                        </time>
                                    ) : (
                                        'Not yet placed'
                                    )}
                                    {' · '}
                                    {order.sellerOrderCount}{' '}
                                    {order.sellerOrderCount === 1 ? 'seller' : 'sellers'}
                                </p>
                            </div>

                            <div className="flex items-center gap-4">
                                <StatusBadge domain="marketplace_order" value={order.status} />
                                <span className="vc-tabular text-[16px] font-semibold">
                                    {order.grandTotal}
                                </span>
                            </div>
                        </li>
                    ))}
                </ul>
            )}

            {orders.lastPage > 1 ? (
                <nav aria-label="Pagination" className="mt-8 flex gap-4 text-[14px]">
                    {orders.currentPage > 1 ? (
                        <Link href={`/account/orders?page=${orders.currentPage - 1}`}>
                            Previous
                        </Link>
                    ) : null}
                    <span aria-current="page">
                        Page {orders.currentPage} of {orders.lastPage}
                    </span>
                    {orders.currentPage < orders.lastPage ? (
                        <Link href={`/account/orders?page=${orders.currentPage + 1}`}>Next</Link>
                    ) : null}
                </nav>
            ) : null}
        </StorefrontLayout>
    );
}
