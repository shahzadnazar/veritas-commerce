import { useForm, usePage } from '@inertiajs/react';
import { AdminLayout } from '../../layouts/AdminLayout';
import { Button } from '../../../design-system/primitives/Button';
import { Field, Input, Textarea } from '../../../design-system/primitives/Field';
import { StatusBadge } from '../../../design-system/primitives/StatusBadge';
import { FlashBanner } from '../../../design-system/patterns/States';
import type { StockLevel } from '../../../design-system/patterns/StockCell';
import type { SharedPageProps } from '../../../shared/types';

interface Movement {
    publicId: string;
    reasonLabel: string;
    onHandChange: number;
    reservedChange: number;
    resultingAvailable: number;
    actorType: string;
    note: string | null;
    at: string;
}

interface DetailProps extends SharedPageProps {
    offer: {
        publicId: string;
        sku: string;
        productTitle: string;
        variantName: string | null;
        status: string;
        sellerName: string | null;
        storeName: string | null;
        effectiveThreshold: number;
    };
    level: StockLevel;
    movements: Movement[];
    can: { adjust: boolean };
}

function signed(value: number): string {
    return value > 0 ? `+${value}` : String(value);
}

/**
 * One seller's listing, read by the platform.
 *
 * An adjustment made here is recorded as the platform's and always carries
 * a written reason — the seller looking at their own history has to be able
 * to see that the marketplace changed their count, and why.
 */
export default function Detail() {
    const { offer, level, movements, can, flash } = usePage<DetailProps>().props;
    const form = useForm({ change: '', reason: '' });

    return (
        <AdminLayout title={offer.productTitle}>
            <FlashBanner success={flash.success} error={flash.error} />

            <div className="mb-8 flex flex-wrap items-center gap-4">
                <StatusBadge domain="stock" value={level.state} />
                <span className="text-[13px] text-[var(--vc-neutral-600)]">
                    {[offer.sellerName, offer.storeName, offer.variantName, offer.sku]
                        .filter(Boolean)
                        .join(' · ')}
                </span>
            </div>

            <div className="mb-10 grid gap-6 sm:grid-cols-3">
                {[
                    { label: 'Available', value: level.available },
                    { label: 'On hand', value: level.onHand },
                    { label: 'Reserved', value: level.reserved },
                ].map((figure) => (
                    <div key={figure.label} className="border-2 border-[var(--vc-text)] p-4">
                        <p className="text-[11px] tracking-[0.08em] text-[var(--vc-neutral-600)] uppercase">
                            {figure.label}
                        </p>
                        <p className="vc-tabular text-[28px]">{figure.value}</p>
                    </div>
                ))}
            </div>

            <div className="grid gap-10 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)]">
                <div>
                    {can.adjust ? (
                        <>
                            <h2 className="mb-2 text-[20px]">Platform adjustment</h2>
                            <p className="mb-4 max-w-[52ch] text-[13px] text-[var(--vc-neutral-700)]">
                                This changes a seller&rsquo;s own count. It is recorded against your
                                account, shown in their history, and the reason you give is kept
                                permanently.
                            </p>
                            <form
                                className="flex flex-col gap-4"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    form.post(`/admin/inventory/${offer.publicId}/adjust`, {
                                        preserveScroll: true,
                                        onSuccess: () => form.reset(),
                                    });
                                }}
                            >
                                <Field label="Change" error={form.errors.change}>
                                    {({ id, describedBy, invalid }) => (
                                        <Input
                                            id={id}
                                            type="number"
                                            required
                                            aria-describedby={describedBy}
                                            invalid={invalid}
                                            value={form.data.change}
                                            onChange={(event) =>
                                                form.setData('change', event.target.value)
                                            }
                                        />
                                    )}
                                </Field>

                                <Field
                                    label="Reason — permanent, and visible to the seller"
                                    error={form.errors.reason}
                                    hint="Give them enough to understand what happened."
                                >
                                    {({ id, describedBy, invalid }) => (
                                        <Textarea
                                            id={id}
                                            required
                                            aria-describedby={describedBy}
                                            invalid={invalid}
                                            value={form.data.reason}
                                            onChange={(event) =>
                                                form.setData('reason', event.target.value)
                                            }
                                        />
                                    )}
                                </Field>

                                <div>
                                    <Button
                                        type="submit"
                                        variant="destructive"
                                        loading={form.processing}
                                        loadingLabel="Applying…"
                                    >
                                        Adjust this seller&rsquo;s stock
                                    </Button>
                                </div>
                            </form>
                        </>
                    ) : (
                        <p className="text-[var(--vc-neutral-700)]">
                            Your role can read stock but not change it.
                        </p>
                    )}
                </div>

                <div>
                    <h2 className="mb-2 text-[20px]">History</h2>
                    {movements.length === 0 ? (
                        <p className="text-[var(--vc-neutral-700)]">
                            Nothing has happened to this listing&rsquo;s stock yet.
                        </p>
                    ) : (
                        <ol className="border-t-2 border-[var(--vc-text)]">
                            {movements.map((movement) => (
                                <li
                                    key={movement.publicId}
                                    className="flex flex-wrap items-baseline gap-x-3 border-b border-[var(--vc-divider)] py-3"
                                >
                                    <span className="vc-tabular w-[52px] text-[15px] font-semibold">
                                        {movement.onHandChange !== 0
                                            ? signed(movement.onHandChange)
                                            : signed(movement.reservedChange)}
                                    </span>
                                    <span className="flex-1 text-[14px]">
                                        <span className="font-semibold">
                                            {movement.reasonLabel}
                                        </span>
                                        <span className="block text-[12px] text-[var(--vc-neutral-600)]">
                                            {movement.at} · by {movement.actorType} ·{' '}
                                            {movement.resultingAvailable} available after
                                        </span>
                                        {movement.note ? (
                                            <span className="mt-1 block text-[13px]">
                                                {movement.note}
                                            </span>
                                        ) : null}
                                    </span>
                                </li>
                            ))}
                        </ol>
                    )}
                </div>
            </div>
        </AdminLayout>
    );
}
