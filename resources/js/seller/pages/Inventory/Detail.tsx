import { useForm, usePage } from '@inertiajs/react';
import { SellerLayout } from '../../layouts/SellerLayout';
import { Button } from '../../../design-system/primitives/Button';
import { Field, Input, Select, Textarea } from '../../../design-system/primitives/Field';
import { StatusBadge } from '../../../design-system/primitives/StatusBadge';
import { FlashBanner } from '../../../design-system/patterns/States';
import type { StockLevel } from '../../../design-system/patterns/StockCell';
import type { SharedPageProps } from '../../../shared/types';

interface Movement {
    publicId: string;
    reason: string;
    reasonLabel: string;
    onHandChange: number;
    reservedChange: number;
    resultingOnHand: number;
    resultingReserved: number;
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
        lowStockThreshold: number | null;
        effectiveThreshold: number;
    };
    level: StockLevel;
    movements: Movement[];
    reasons: { value: string; label: string; requiresNote: string }[];
    can: { manage: boolean };
}

function signed(value: number): string {
    return value > 0 ? `+${value}` : String(value);
}

/**
 * One listing's stock, and everything that ever happened to it.
 *
 * The history is the point. A count that changed and nobody can say why is
 * how a marketplace loses an argument with a seller, so every row names
 * the reason, who did it and what the number became.
 */
export default function Detail() {
    const { offer, level, movements, reasons, can, flash } = usePage<DetailProps>().props;

    const adjustment = useForm({ change: '', reason: reasons[0]?.value ?? '', note: '' });
    const opening = useForm({ quantity: '' });
    const threshold = useForm({
        low_stock_threshold:
            offer.lowStockThreshold === null ? '' : String(offer.lowStockThreshold),
    });

    const chosen = reasons.find((reason) => reason.value === adjustment.data.reason);
    const noteRequired = chosen?.requiresNote === 'yes';
    const hasHistory = movements.length > 0;

    return (
        <SellerLayout title={offer.productTitle}>
            <FlashBanner success={flash.success} error={flash.error} />

            <div className="mb-8 flex flex-wrap items-center gap-4">
                <StatusBadge domain="stock" value={level.state} />
                <span className="text-[13px] text-[var(--vc-neutral-600)]">
                    {[offer.variantName, offer.sku].filter(Boolean).join(' · ')}
                </span>
            </div>

            <div className="mb-10 grid gap-6 sm:grid-cols-3">
                {[
                    { label: 'Available', value: level.available, hint: 'What a customer can buy' },
                    { label: 'On hand', value: level.onHand, hint: 'Physically counted' },
                    { label: 'Reserved', value: level.reserved, hint: 'Held by checkouts' },
                ].map((figure) => (
                    <div key={figure.label} className="border-2 border-[var(--vc-text)] p-4">
                        <p className="text-[11px] tracking-[0.08em] text-[var(--vc-neutral-600)] uppercase">
                            {figure.label}
                        </p>
                        <p className="vc-tabular text-[28px]">{figure.value}</p>
                        <p className="text-[12px] text-[var(--vc-neutral-600)]">{figure.hint}</p>
                    </div>
                ))}
            </div>

            <div className="grid gap-10 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)]">
                <div>
                    {can.manage && !hasHistory ? (
                        <>
                            <h2 className="mb-2 text-[20px]">Opening stock</h2>
                            <p className="mb-4 max-w-[52ch] text-[13px] text-[var(--vc-neutral-700)]">
                                How many you have right now. This is recorded as the first entry in
                                the listing&rsquo;s history, so the count always has an explanation
                                behind it.
                            </p>
                            <form
                                className="mb-10 flex flex-col gap-4"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    opening.post(
                                        `/seller/inventory/${offer.publicId}/opening-stock`,
                                        {
                                            preserveScroll: true,
                                        },
                                    );
                                }}
                            >
                                <Field label="Units" error={opening.errors.quantity}>
                                    {({ id, describedBy, invalid }) => (
                                        <Input
                                            id={id}
                                            type="number"
                                            min={1}
                                            required
                                            aria-describedby={describedBy}
                                            invalid={invalid}
                                            value={opening.data.quantity}
                                            onChange={(event) =>
                                                opening.setData('quantity', event.target.value)
                                            }
                                        />
                                    )}
                                </Field>
                                <div>
                                    <Button
                                        type="submit"
                                        variant="primary"
                                        loading={opening.processing}
                                        loadingLabel="Recording…"
                                    >
                                        Record opening stock
                                    </Button>
                                </div>
                            </form>
                        </>
                    ) : null}

                    {can.manage && hasHistory ? (
                        <>
                            <h2 className="mb-2 text-[20px]">Adjust stock</h2>
                            <p className="mb-4 max-w-[52ch] text-[13px] text-[var(--vc-neutral-700)]">
                                Positive to add, negative to remove. Every adjustment needs a reason
                                and is kept permanently.
                            </p>
                            <form
                                className="mb-10 flex flex-col gap-4"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    adjustment.post(`/seller/inventory/${offer.publicId}/adjust`, {
                                        preserveScroll: true,
                                        onSuccess: () => adjustment.reset(),
                                    });
                                }}
                            >
                                <Field
                                    label="Change"
                                    error={adjustment.errors.change}
                                    hint="e.g. 20 for a delivery, -3 for breakages"
                                >
                                    {({ id, describedBy, invalid }) => (
                                        <Input
                                            id={id}
                                            type="number"
                                            required
                                            aria-describedby={describedBy}
                                            invalid={invalid}
                                            value={adjustment.data.change}
                                            onChange={(event) =>
                                                adjustment.setData('change', event.target.value)
                                            }
                                        />
                                    )}
                                </Field>

                                <Field label="Reason" error={adjustment.errors.reason}>
                                    {({ id, describedBy, invalid }) => (
                                        <Select
                                            id={id}
                                            aria-describedby={describedBy}
                                            invalid={invalid}
                                            value={adjustment.data.reason}
                                            onChange={(event) =>
                                                adjustment.setData('reason', event.target.value)
                                            }
                                        >
                                            {reasons.map((reason) => (
                                                <option key={reason.value} value={reason.value}>
                                                    {reason.label}
                                                </option>
                                            ))}
                                        </Select>
                                    )}
                                </Field>

                                <Field
                                    label={noteRequired ? 'What happened' : 'Note (optional)'}
                                    error={adjustment.errors.note}
                                    hint={
                                        noteRequired
                                            ? '“Other” explains nothing on its own.'
                                            : undefined
                                    }
                                >
                                    {({ id, describedBy, invalid }) => (
                                        <Textarea
                                            id={id}
                                            required={noteRequired}
                                            aria-describedby={describedBy}
                                            invalid={invalid}
                                            value={adjustment.data.note}
                                            onChange={(event) =>
                                                adjustment.setData('note', event.target.value)
                                            }
                                        />
                                    )}
                                </Field>

                                <div>
                                    <Button
                                        type="submit"
                                        variant="primary"
                                        loading={adjustment.processing}
                                        loadingLabel="Saving…"
                                    >
                                        Apply adjustment
                                    </Button>
                                </div>
                            </form>
                        </>
                    ) : null}

                    {can.manage ? (
                        <>
                            <h2 className="mb-2 text-[20px]">Low-stock warning</h2>
                            <form
                                className="flex flex-col gap-4"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    threshold.patch(
                                        `/seller/inventory/${offer.publicId}/threshold`,
                                        {
                                            preserveScroll: true,
                                        },
                                    );
                                }}
                            >
                                <Field
                                    label="Warn me at"
                                    error={threshold.errors.low_stock_threshold}
                                    hint={`Leave blank to use your store's setting (currently ${offer.effectiveThreshold}). Zero means never warn me.`}
                                >
                                    {({ id, describedBy, invalid }) => (
                                        <Input
                                            id={id}
                                            type="number"
                                            min={0}
                                            aria-describedby={describedBy}
                                            invalid={invalid}
                                            value={threshold.data.low_stock_threshold}
                                            onChange={(event) =>
                                                threshold.setData(
                                                    'low_stock_threshold',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    )}
                                </Field>
                                <div>
                                    <Button
                                        type="submit"
                                        variant="secondary"
                                        loading={threshold.processing}
                                        loadingLabel="Saving…"
                                    >
                                        Save threshold
                                    </Button>
                                </div>
                            </form>
                        </>
                    ) : null}
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
        </SellerLayout>
    );
}
