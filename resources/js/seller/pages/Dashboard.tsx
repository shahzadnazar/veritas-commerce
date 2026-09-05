import { Link, usePage } from '@inertiajs/react';
import { SellerLayout } from '../layouts/SellerLayout';
import { Button } from '../../design-system/primitives/Button';
import { StatusBadge } from '../../design-system/primitives/StatusBadge';
import { FlashBanner } from '../../design-system/patterns/States';
import { BalancePanel } from '../components/BalancePanel';
import type { PayoutEligibilityView, SellerFinancialPositionView } from '../../shared/commerce';
import type { SharedPageProps } from '../../shared/types';

interface SetupStep {
    key: string;
    label: string;
    done: boolean;
    href: string;
}

interface DashboardProps extends SharedPageProps {
    seller: {
        legalName: string;
        reference: string;
        status: string;
        role: string;
        roleLabel: string;
    };
    store: {
        name: string;
        slug: string;
        isOpen: boolean;
        publicUrl: string;
    } | null;
    setup: SetupStep[];
    /** Null when this member's role cannot see orders. */
    fulfilment: {
        awaitingConfirmation: number;
        preparing: number;
        inTransit: number;
        delivered: number;
        completed: number;
        lowStock: number;
    } | null;
    /** Null when this member's role cannot see the money. */
    earnings:
        | (SellerFinancialPositionView & {
              nextReleaseAt: string | null;
              eligibility: PayoutEligibilityView;
          })
        | null;
    can: {
        manageStore: boolean;
        manageMembers: boolean;
        seeFinance: boolean;
        seeOrders: boolean;
        seePayouts: boolean;
    };
}

/**
 * What needs doing, and where the money is.
 *
 * A seller who has just been approved still sees only the setup steps: the
 * counts and the balances are real numbers or they are not shown, never
 * sample figures and never a chart of a trend with one data point.
 *
 * The three money figures are kept apart deliberately. One "earnings"
 * number would reasonably be read as spendable, and pending, clearing and
 * available are three different promises — only the last is money the
 * seller will be able to ask for, and only from M7.
 */
export default function Dashboard() {
    const { seller, store, setup, fulfilment, earnings, can, flash } =
        usePage<DashboardProps>().props;

    const remaining = setup.filter((step) => !step.done);

    return (
        <SellerLayout
            title="Dashboard"
            actions={
                store !== null ? (
                    <a href={store.publicUrl} className="text-[13px] underline underline-offset-4">
                        View your store page
                    </a>
                ) : undefined
            }
        >
            <FlashBanner success={flash.success} error={flash.error} />

            {fulfilment !== null ? (
                <section aria-labelledby="work-heading" className="mb-10">
                    <h2 id="work-heading" className="mb-3 text-[20px]">
                        Waiting on you
                    </h2>

                    <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <WorkCount
                            label="To confirm"
                            value={fulfilment.awaitingConfirmation}
                            href="/seller/orders?status=paid"
                            urgent
                        />
                        <WorkCount
                            label="Being prepared"
                            value={fulfilment.preparing}
                            href="/seller/orders?status=processing"
                        />
                        <WorkCount
                            label="On their way"
                            value={fulfilment.inTransit}
                            href="/seller/orders?status=shipped"
                        />
                        <WorkCount
                            label="Delivered"
                            value={fulfilment.delivered}
                            href="/seller/orders?status=delivered"
                        />
                        <WorkCount
                            label="Completed"
                            value={fulfilment.completed}
                            href="/seller/orders?status=completed"
                        />
                        <WorkCount
                            label="Listings low on stock"
                            value={fulfilment.lowStock}
                            href="/seller/inventory"
                            urgent={fulfilment.lowStock > 0}
                        />
                    </ul>
                </section>
            ) : null}

            {earnings !== null ? (
                <section className="mb-10">
                    {/*
                     * The five figures and the withdrawable answer come
                     * from the backend, and the CTA below appears only
                     * when the backend says a payout can be made. §73:
                     * React does not recreate the eligibility rule.
                     */}
                    <BalancePanel
                        position={earnings}
                        eligibility={earnings.eligibility}
                        nextReleaseAt={earnings.nextReleaseAt}
                    />

                    {can.seePayouts ? (
                        <p className="mt-3 text-[13px]">
                            {earnings.eligibility.canRequest ? (
                                <Link
                                    href="/seller/payouts"
                                    className="underline underline-offset-4"
                                >
                                    Request a payout
                                </Link>
                            ) : (
                                <Link
                                    href="/seller/earnings"
                                    className="underline underline-offset-4"
                                >
                                    See your statement
                                </Link>
                            )}
                        </p>
                    ) : null}
                </section>
            ) : null}

            <section className="mb-10 border-2 border-[var(--vc-divider)] p-5">
                <div className="flex flex-wrap items-center gap-3">
                    <h2 className="text-[24px]">{store?.name ?? seller.legalName}</h2>
                    <StatusBadge domain="seller" value={seller.status} />
                    {store !== null && !store.isOpen ? (
                        <span className="text-[12px] text-[var(--vc-neutral-600)]">
                            Closed for orders
                        </span>
                    ) : null}
                </div>

                <dl className="mt-4 grid gap-x-8 gap-y-1 text-[13px] sm:grid-cols-2">
                    <div className="flex gap-2">
                        <dt className="text-[var(--vc-neutral-600)]">Registered as</dt>
                        <dd>{seller.legalName}</dd>
                    </div>
                    <div className="flex gap-2">
                        <dt className="text-[var(--vc-neutral-600)]">Your role</dt>
                        <dd>{seller.roleLabel}</dd>
                    </div>
                    <div className="flex gap-2">
                        <dt className="text-[var(--vc-neutral-600)]">Seller reference</dt>
                        <dd className="vc-tabular">{seller.reference}</dd>
                    </div>
                    {store !== null ? (
                        <div className="flex gap-2">
                            <dt className="text-[var(--vc-neutral-600)]">Store address</dt>
                            <dd className="vc-tabular">/stores/{store.slug}</dd>
                        </div>
                    ) : null}
                </dl>

                {seller.status === 'suspended' ? (
                    <p
                        role="alert"
                        className="mt-4 max-w-[62ch] text-[13px] text-[var(--vc-accent-800)]"
                    >
                        This account is suspended. Your records are intact and nothing has been
                        deleted, but the store is not visible to customers and no changes can be
                        made until the marketplace team lifts the suspension.
                    </p>
                ) : null}
            </section>

            <section>
                <h2 className="mb-1 text-[22px]">
                    {remaining.length === 0 ? 'Setup complete' : 'Finish setting up'}
                </h2>
                <p className="mb-5 max-w-[62ch] text-[14px] text-[var(--vc-neutral-700)]">
                    {remaining.length === 0
                        ? 'Your store is ready. Products, stock and orders arrive in the next release.'
                        : 'These are the steps left before your store page is worth sending someone to.'}
                </p>

                <ol className="max-w-[620px] border-t-2 border-[var(--vc-text)]">
                    {setup.map((step) => (
                        <li
                            key={step.key}
                            className="flex items-center gap-4 border-b border-[var(--vc-divider)] py-3"
                        >
                            <span
                                aria-hidden="true"
                                className={[
                                    'flex h-[22px] w-[22px] shrink-0 items-center justify-center border-2 text-[12px] font-bold',
                                    step.done
                                        ? 'border-[var(--vc-text)] bg-[var(--vc-text)] text-[var(--vc-bg)]'
                                        : 'border-dashed border-[var(--vc-neutral-400)]',
                                ].join(' ')}
                            >
                                {step.done ? '✓' : ''}
                            </span>

                            <span className="flex-1 text-[14px]">
                                {step.label}
                                <span className="sr-only">
                                    {step.done ? ' — done' : ' — to do'}
                                </span>
                            </span>

                            {!step.done ? (
                                <Link
                                    href={step.href}
                                    className="text-[13px] underline underline-offset-4"
                                >
                                    Do this
                                </Link>
                            ) : null}
                        </li>
                    ))}
                </ol>
            </section>

            <section className="mt-10 flex flex-wrap gap-2">
                {can.manageStore ? (
                    <Link href="/seller/store">
                        <Button variant="primary">Store settings</Button>
                    </Link>
                ) : null}
                {can.manageMembers ? (
                    <Link href="/seller/team">
                        <Button variant="secondary">Invite a colleague</Button>
                    </Link>
                ) : null}
            </section>
        </SellerLayout>
    );
}

/**
 * One count, and where to act on it.
 *
 * A number with nowhere to go is a decoration; every one of these is a
 * link to the filtered list that contains exactly those orders.
 */
function WorkCount({
    label,
    value,
    href,
    urgent = false,
}: {
    label: string;
    value: number;
    href: string;
    urgent?: boolean;
}) {
    return (
        <li
            className={[
                'border-2 p-4',
                urgent && value > 0 ? 'border-[var(--vc-accent)]' : 'border-[var(--vc-divider)]',
            ].join(' ')}
        >
            <Link href={href} className="block">
                <span className="vc-tabular block text-[28px] leading-none">{value}</span>
                <span className="mt-1 block text-[13px] text-[var(--vc-neutral-700)]">{label}</span>
            </Link>
        </li>
    );
}
