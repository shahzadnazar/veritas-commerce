import { Link, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Modal } from '../../../design-system/patterns/Modal';
import { EmptyState } from '../../../design-system/patterns/States';
import { Button } from '../../../design-system/primitives/Button';
import { Field, Input, Textarea } from '../../../design-system/primitives/Field';
import { StatusBadge } from '../../../design-system/primitives/StatusBadge';
import type { SharedPageProps } from '../../../shared/types';
import { AdminLayout } from '../../layouts/AdminLayout';

interface Attempt {
    publicId: string;
    status: string;
    provider: string;
    providerStatus: string | null;
    reference: string | null;
    method: string | null;
    failureCode: string | null;
    failureMessage: string | null;
    createdAt: string;
    succeededAt: string | null;
    failedAt: string | null;
}

interface Transaction {
    publicId: string;
    type: string;
    amountMinor: number;
    amount: string;
    status: string;
    occurredAt: string;
}

interface RefundRecord {
    reference: string;
    status: string;
    amount: string;
    amountMinor: number;
    reason: string | null;
    requestedAt: string;
    succeededAt: string | null;
    failedAt: string | null;
    allocations: {
        orderItemId: number;
        amountMinor: number;
        commissionReversedMinor: number;
        earningReversedMinor: number;
    }[];
}

interface RefundableItem {
    id: number;
    sellerOrderReference: string;
    title: string;
    quantity: number;
    lineTotalMinor: number;
    refundedMinor: number;
    refundableMinor: number;
    currency: string;
}

interface ProviderEvent {
    eventId: string;
    type: string;
    status: string;
    attempts: number;
    receivedAt: string;
    processedAt: string | null;
    failedAt: string | null;
}

interface ShowProps extends SharedPageProps {
    order: { reference: string; status: string; placedAt: string | null; grandTotal: string };
    payment: {
        publicId: string;
        provider: string;
        status: string;
        capturedAt: string | null;
        amount: string;
        amountMinor: number;
        refundedMinor: number;
        refundableMinor: number;
        currency: string;
    } | null;
    attempts: Attempt[];
    transactions: Transaction[];
    refunds: RefundRecord[];
    refundableItems: RefundableItem[];
    providerEvents: ProviderEvent[];
    can: { refund: boolean; viewEvents: boolean };
}

const money = (minor: number, currency: string) =>
    new Intl.NumberFormat('en-US', { style: 'currency', currency }).format(minor / 100);

/**
 * One order's money, end to end.
 *
 * Every attempt is listed, failures included, because "did it charge?" is
 * the question support is actually asked and a screen that showed only the
 * successful attempt would answer it by omission. The provider's own
 * decline codes are here and nowhere the customer can see them (§53).
 *
 * The refund control is the only place in the application a person can
 * move money back out, so it names the items it is returning and demands a
 * written reason — the server requires both, and this is the accessible
 * way to collect them, not the thing enforcing them.
 */
export default function Show() {
    const {
        order,
        payment,
        attempts,
        transactions,
        refunds,
        refundableItems,
        providerEvents,
        can,
    } = usePage<ShowProps>().props;

    const [dialogOpen, setDialogOpen] = useState(false);

    /*
     * One key per opening of the dialog, minted in the click that opens
     * it. A double-clicked confirm sends the same key twice and produces
     * one refund; a second, deliberate refund on the same order gets a new
     * key and is allowed through.
     */
    const [refundKey, setRefundKey] = useState('');

    const openRefundDialog = () => {
        setRefundKey(`refund-${order.reference}-${Date.now().toString(36)}`);
        setDialogOpen(true);
    };

    return (
        <AdminLayout title={`Payment — ${order.reference}`}>
            <p className="mb-6 text-[13px]">
                <Link href="/admin/payments" className="underline underline-offset-4">
                    All payments
                </Link>
                {' · '}
                <Link
                    href={`/admin/orders/${order.reference}`}
                    className="underline underline-offset-4"
                >
                    Order detail
                </Link>
            </p>

            {payment === null ? (
                <EmptyState
                    title="Nothing has been captured for this order"
                    body="The attempts below are everything the platform has recorded."
                />
            ) : (
                <section className="mb-10 border-2 border-[var(--vc-text)] p-5">
                    <div className="flex flex-wrap items-baseline gap-x-6 gap-y-2">
                        <div>
                            <p className="text-[12px] tracking-[0.08em] text-[var(--vc-neutral-600)] uppercase">
                                Captured
                            </p>
                            <p className="vc-tabular text-[24px]">{payment.amount}</p>
                        </div>
                        <div>
                            <p className="text-[12px] tracking-[0.08em] text-[var(--vc-neutral-600)] uppercase">
                                Refunded
                            </p>
                            <p className="vc-tabular text-[24px]">
                                {money(payment.refundedMinor, payment.currency)}
                            </p>
                        </div>
                        <div>
                            <p className="text-[12px] tracking-[0.08em] text-[var(--vc-neutral-600)] uppercase">
                                Still refundable
                            </p>
                            <p className="vc-tabular text-[24px]">
                                {money(payment.refundableMinor, payment.currency)}
                            </p>
                        </div>
                        <StatusBadge domain="payment" value={payment.status} />
                    </div>

                    {can.refund && payment.refundableMinor > 0 ? (
                        <div className="mt-5">
                            <Button variant="destructive" onClick={openRefundDialog}>
                                Issue a refund
                            </Button>
                        </div>
                    ) : null}
                </section>
            )}

            <Section title="Attempts">
                {attempts.length === 0 ? (
                    <p className="text-[14px] text-[var(--vc-neutral-700)]">
                        No payment has been prepared for this order.
                    </p>
                ) : (
                    <ol className="border-t border-[var(--vc-divider)]">
                        {attempts.map((attempt) => (
                            <li
                                key={attempt.publicId}
                                className="border-b border-[var(--vc-divider)] py-3 text-[14px]"
                            >
                                <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                                    <StatusBadge domain="payment_attempt" value={attempt.status} />
                                    <span className="vc-tabular text-[12px] text-[var(--vc-neutral-600)]">
                                        {attempt.reference ?? 'not yet prepared'}
                                    </span>
                                    <time
                                        dateTime={attempt.createdAt}
                                        className="text-[12px] text-[var(--vc-neutral-600)]"
                                    >
                                        {new Date(attempt.createdAt).toLocaleString()}
                                    </time>
                                </div>

                                {attempt.method ? (
                                    <p className="mt-1 text-[13px]">{attempt.method}</p>
                                ) : null}

                                {attempt.failureCode ? (
                                    <p className="mt-1 text-[13px] text-[var(--vc-neutral-700)]">
                                        Provider said: <code>{attempt.failureCode}</code>
                                        {attempt.failureMessage
                                            ? ` — ${attempt.failureMessage}`
                                            : ''}
                                    </p>
                                ) : null}
                            </li>
                        ))}
                    </ol>
                )}
            </Section>

            <Section title="Movements">
                {transactions.length === 0 ? (
                    <p className="text-[14px] text-[var(--vc-neutral-700)]">
                        Nothing has moved yet.
                    </p>
                ) : (
                    <ul className="border-t border-[var(--vc-divider)]">
                        {transactions.map((transaction) => (
                            <li
                                key={transaction.publicId}
                                className="flex justify-between gap-4 border-b border-[var(--vc-divider)] py-3 text-[14px]"
                            >
                                <span>
                                    {transaction.type}
                                    <span className="block text-[12px] text-[var(--vc-neutral-600)]">
                                        <time dateTime={transaction.occurredAt}>
                                            {new Date(transaction.occurredAt).toLocaleString()}
                                        </time>
                                    </span>
                                </span>
                                <span className="vc-tabular whitespace-nowrap">
                                    {transaction.amountMinor < 0 ? '−' : ''}
                                    {transaction.amount}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </Section>

            <Section title="Refunds">
                {refunds.length === 0 ? (
                    <p className="text-[14px] text-[var(--vc-neutral-700)]">
                        Nothing has been refunded on this order.
                    </p>
                ) : (
                    <ul className="border-t border-[var(--vc-divider)]">
                        {refunds.map((refund) => (
                            <li
                                key={refund.reference}
                                className="border-b border-[var(--vc-divider)] py-3 text-[14px]"
                            >
                                <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                                    <span className="flex items-baseline gap-3">
                                        <span className="font-semibold">{refund.reference}</span>
                                        <StatusBadge domain="refund" value={refund.status} />
                                    </span>
                                    <span className="vc-tabular">{refund.amount}</span>
                                </div>
                                {refund.reason ? (
                                    <p className="mt-1 text-[13px] text-[var(--vc-neutral-700)]">
                                        {refund.reason}
                                    </p>
                                ) : null}
                            </li>
                        ))}
                    </ul>
                )}
            </Section>

            {can.viewEvents ? (
                <Section title="Provider events">
                    {providerEvents.length === 0 ? (
                        <p className="text-[14px] text-[var(--vc-neutral-700)]">
                            No provider events have arrived for this order.
                        </p>
                    ) : (
                        <ul className="border-t border-[var(--vc-divider)]">
                            {providerEvents.map((event) => (
                                <li
                                    key={event.eventId}
                                    className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1 border-b border-[var(--vc-divider)] py-3 text-[14px]"
                                >
                                    <span>
                                        {event.type}
                                        <span className="block vc-tabular text-[12px] text-[var(--vc-neutral-600)]">
                                            {event.eventId}
                                        </span>
                                    </span>
                                    <span className="flex items-baseline gap-3">
                                        <StatusBadge domain="provider_event" value={event.status} />
                                        <time
                                            dateTime={event.receivedAt}
                                            className="text-[12px] text-[var(--vc-neutral-600)]"
                                        >
                                            {new Date(event.receivedAt).toLocaleString()}
                                        </time>
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Section>
            ) : null}

            {payment !== null ? (
                <RefundDialog
                    open={dialogOpen}
                    onClose={() => setDialogOpen(false)}
                    orderReference={order.reference}
                    idempotencyKey={refundKey}
                    currency={payment.currency}
                    items={refundableItems}
                />
            ) : null}
        </AdminLayout>
    );
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
    return (
        <section className="mb-10">
            <h2 className="mb-3 text-[20px]">{title}</h2>
            {children}
        </section>
    );
}

/**
 * The refund form.
 *
 * Per item, because a marketplace order is several sellers' money in one
 * payment: "refund $50" is not a financial instruction until it says whose
 * $50, and the reversal against each seller's earning is taken from that
 * item's own snapshot. The idempotency key is generated once when the
 * dialog opens, so a double-clicked confirm is one refund.
 */
function RefundDialog({
    open,
    onClose,
    orderReference,
    idempotencyKey,
    currency,
    items,
}: {
    open: boolean;
    onClose: () => void;
    orderReference: string;
    idempotencyKey: string;
    currency: string;
    items: RefundableItem[];
}) {
    const refundable = items.filter((item) => item.refundableMinor > 0);

    /*
     * A refusal from the domain — an amount above what remains, a
     * provider outage — comes back on the shared error bag under its own
     * key rather than against a field, because it is about the refund as
     * a whole and not about one input.
     */
    const { errors } = usePage<SharedPageProps & { errors: Record<string, string> }>().props;

    const form = useForm<{
        reason: string;
        idempotency_key: string;
        lines: { order_item_id: number; amount_minor: number; quantity: number }[];
    }>({
        reason: '',
        idempotency_key: '',
        lines: [],
    });

    const amountFor = (itemId: number) =>
        form.data.lines.find((line) => line.order_item_id === itemId)?.amount_minor ?? 0;

    const setAmount = (item: RefundableItem, minor: number) => {
        const clamped = Math.max(0, Math.min(minor, item.refundableMinor));
        const others = form.data.lines.filter((line) => line.order_item_id !== item.id);

        form.setData(
            'lines',
            clamped === 0
                ? others
                : [
                      ...others,
                      {
                          order_item_id: item.id,
                          amount_minor: clamped,
                          // A full line refund returns the whole quantity;
                          // a partial one returns money, not units.
                          quantity: clamped === item.lineTotalMinor ? item.quantity : 0,
                      },
                  ],
        );
    };

    const total = form.data.lines.reduce((sum, line) => sum + line.amount_minor, 0);

    return (
        <Modal
            open={open}
            title="Issue a refund"
            consequence={
                'The money is returned to the customer and each seller’s earning for the items ' +
                'below is reversed. Refunds cannot be undone.'
            }
            onClose={onClose}
            actions={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        variant="destructive"
                        loading={form.processing}
                        loadingLabel="Refunding…"
                        disabled={total === 0 || form.data.reason.trim().length < 8}
                        onClick={() => {
                            form.transform((data) => ({
                                ...data,
                                idempotency_key: idempotencyKey,
                            }));

                            form.post(`/admin/payments/${orderReference}/refunds`, {
                                preserveScroll: true,
                                onSuccess: () => {
                                    form.reset();
                                    onClose();
                                },
                            });
                        }}
                    >
                        {`Refund ${money(total, currency)}`}
                    </Button>
                </>
            }
        >
            {refundable.length === 0 ? (
                <p className="text-[14px]">Every item on this order has already been refunded.</p>
            ) : (
                <>
                    <ul className="mb-5 border-t border-[var(--vc-divider)]">
                        {refundable.map((item) => (
                            <li
                                key={item.id}
                                className="flex flex-wrap items-end justify-between gap-3 border-b border-[var(--vc-divider)] py-3"
                            >
                                <span className="text-[14px]">
                                    {item.title}
                                    <span className="block text-[12px] text-[var(--vc-neutral-600)]">
                                        {item.sellerOrderReference} · up to{' '}
                                        {money(item.refundableMinor, item.currency)}
                                    </span>
                                </span>

                                <span className="flex items-end gap-2">
                                    <Field label={`Amount (${item.currency})`}>
                                        {({ id }) => (
                                            <Input
                                                id={id}
                                                type="number"
                                                min={0}
                                                max={item.refundableMinor / 100}
                                                step="0.01"
                                                className="w-32"
                                                value={
                                                    amountFor(item.id) === 0
                                                        ? ''
                                                        : (amountFor(item.id) / 100).toFixed(2)
                                                }
                                                onChange={(event) =>
                                                    setAmount(
                                                        item,
                                                        Math.round(
                                                            Number(event.target.value || 0) * 100,
                                                        ),
                                                    )
                                                }
                                            />
                                        )}
                                    </Field>

                                    <Button
                                        variant="ghost"
                                        type="button"
                                        onClick={() => setAmount(item, item.refundableMinor)}
                                    >
                                        All
                                    </Button>
                                </span>
                            </li>
                        ))}
                    </ul>

                    <Field
                        label="Reason — recorded permanently against this refund"
                        error={form.errors.reason}
                        hint="Say what happened. Somebody reconciles this months later."
                    >
                        {({ id, describedBy, invalid }) => (
                            <Textarea
                                id={id}
                                aria-describedby={describedBy}
                                invalid={invalid}
                                value={form.data.reason}
                                onChange={(event) => form.setData('reason', event.target.value)}
                            />
                        )}
                    </Field>

                    {errors.refund ? (
                        <p role="alert" className="mt-3 text-[13px] text-[var(--vc-accent-800)]">
                            {errors.refund}
                        </p>
                    ) : null}
                </>
            )}
        </Modal>
    );
}
