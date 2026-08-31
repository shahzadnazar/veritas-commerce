import { Link, usePage } from '@inertiajs/react';
import { SellerLayout } from '../layouts/SellerLayout';
import { Button } from '../../design-system/primitives/Button';
import { StatusBadge } from '../../design-system/primitives/StatusBadge';
import { FlashBanner } from '../../design-system/patterns/States';
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
    can: { manageStore: boolean; manageMembers: boolean };
}

/**
 * Deliberately thin.
 *
 * A seller who has just been approved has no orders, no earnings and no
 * stock, so this screen shows what is true — who they are, that they are
 * approved, and what is left to set up — instead of stat cards reading
 * zero, or worse, sample figures.
 */
export default function Dashboard() {
    const { seller, store, setup, can, flash } = usePage<DashboardProps>().props;

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
