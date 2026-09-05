import { Link, useForm, usePage } from '@inertiajs/react';
import { SellerLayout } from '../../layouts/SellerLayout';
import { BalancePanel } from '../../components/BalancePanel';
import { Table, type Column } from '../../../design-system/patterns/Table';
import { Button } from '../../../design-system/primitives/Button';
import { Field, Input } from '../../../design-system/primitives/Field';
import { StatusBadge } from '../../../design-system/primitives/StatusBadge';
import { EmptyState, FlashBanner } from '../../../design-system/patterns/States';
import type {
    PayoutEligibilityView,
    PayoutSummaryView,
    SellerDestinationView,
    SellerFinancialPositionView,
} from '../../../shared/commerce';
import type { SharedPageProps } from '../../../shared/types';

interface PayoutsIndexProps extends SharedPageProps {
    position: SellerFinancialPositionView;
    eligibility: PayoutEligibilityView;
    payouts: PayoutSummaryView[];
    destination: SellerDestinationView | null;
    can: {
        viewPayouts: boolean;
        requestPayout: boolean;
        manageDestination: boolean;
        minimum: string;
        minimumMinor: number;
        currency: string;
    };
}

/**
 * Asking for money, and the history of having asked.
 *
 * The request form appears only when the backend says a payout can be
 * made. §17 and §73: this component never compares a balance to a
 * minimum, subtracts a reservation or looks for an open request — it reads
 * `eligibility.canRequest` and renders the server's explanation when the
 * answer is no.
 */
export default function PayoutsIndex() {
    const { position, eligibility, payouts, destination, can, flash } =
        usePage<PayoutsIndexProps>().props;

    const columns: Column<PayoutSummaryView>[] = [
        {
            key: 'reference',
            header: 'Reference',
            render: (row) => (
                <Link
                    href={`/seller/payouts/${row.reference}`}
                    className="underline underline-offset-4"
                >
                    {row.reference}
                </Link>
            ),
        },
        {
            key: 'requested',
            header: 'Requested',
            render: (row) => new Date(row.requestedAt).toLocaleDateString(),
        },
        {
            key: 'destination',
            header: 'To',
            render: (row) => row.destinationLabel,
        },
        {
            key: 'paid',
            header: 'Sent',
            render: (row) => (row.paidAt ? new Date(row.paidAt).toLocaleDateString() : '—'),
        },
        { key: 'amount', header: 'Amount', numeric: true, render: (row) => row.amount },
        {
            key: 'status',
            header: 'Status',
            render: (row) => <StatusBadge domain="payout" value={row.status} />,
        },
    ];

    return (
        <SellerLayout
            title="Payouts"
            actions={
                <Link href="/seller/earnings" className="text-[13px] underline underline-offset-4">
                    Statement
                </Link>
            }
        >
            <FlashBanner success={flash.success} error={flash.error} />

            <div className="mb-10">
                <BalancePanel position={position} eligibility={eligibility} />
            </div>

            {can.requestPayout ? (
                <RequestForm eligibility={eligibility} minimum={can.minimum} />
            ) : null}

            {can.manageDestination ? (
                <DestinationForm destination={destination} currency={can.currency} />
            ) : destination !== null ? (
                <section className="mb-10">
                    <h2 className="mb-1 text-[20px]">Where your money goes</h2>
                    <p className="text-[13px] text-[var(--vc-neutral-700)]">
                        {destination.label} — {destination.typeLabel}, {destination.currency}
                    </p>
                </section>
            ) : null}

            <section aria-labelledby="history-heading">
                <h2 id="history-heading" className="mb-3 text-[20px]">
                    Your payouts
                </h2>

                <Table
                    caption="Payout history"
                    columns={columns}
                    rows={payouts}
                    rowKey={(row) => row.id}
                    empty={
                        <EmptyState
                            title="No payouts yet"
                            body="Once your delivered orders finish clearing you can withdraw what you have earned."
                        />
                    }
                />
            </section>
        </SellerLayout>
    );
}

/**
 * The amount, and only the amount.
 *
 * The maximum is not enforced here. A `max` attribute is a courtesy that
 * stops a typo; the real cap is applied inside RequestPayout against a
 * balance read under a lock, because anything a browser sends can be
 * changed before it arrives (§9).
 */
function RequestForm({
    eligibility,
    minimum,
}: {
    eligibility: PayoutEligibilityView;
    minimum: string;
}) {
    const form = useForm({ amount_minor: eligibility.withdrawableMinor });

    if (!eligibility.canRequest) {
        return (
            <section className="mb-10 border-l-2 border-[var(--vc-neutral-400)] p-3">
                <h2 className="mb-1 text-[20px]">Request a payout</h2>
                <p className="max-w-[62ch] text-[13px] text-[var(--vc-neutral-700)]">
                    {eligibility.message}
                </p>
                {eligibility.openPayoutReference ? (
                    <p className="mt-1 text-[13px]">
                        <Link
                            href={`/seller/payouts/${eligibility.openPayoutReference}`}
                            className="underline underline-offset-4"
                        >
                            View {eligibility.openPayoutReference}
                        </Link>
                    </p>
                ) : null}
            </section>
        );
    }

    return (
        <section className="mb-10 border-l-2 border-[var(--vc-text)] p-3">
            <h2 className="mb-1 text-[20px]">Request a payout</h2>
            <p className="mb-3 max-w-[62ch] text-[13px] text-[var(--vc-neutral-700)]">
                You can withdraw up to {eligibility.withdrawable}. The minimum is {minimum}. The
                amount is reserved as soon as you ask, and our finance team reviews every request.
            </p>

            <form
                className="flex flex-wrap items-end gap-3"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post('/seller/payouts', { preserveScroll: true });
                }}
            >
                <Field label="Amount in cents" error={form.errors.amount_minor}>
                    {({ id, describedBy, invalid }) => (
                        <Input
                            id={id}
                            aria-describedby={describedBy}
                            type="number"
                            min={1}
                            step={1}
                            inputMode="numeric"
                            value={form.data.amount_minor}
                            invalid={invalid}
                            onChange={(event) =>
                                form.setData('amount_minor', Number(event.target.value))
                            }
                        />
                    )}
                </Field>

                <Button type="submit" disabled={form.processing}>
                    {form.processing ? 'Requesting…' : 'Request payout'}
                </Button>
            </form>
        </section>
    );
}

/**
 * Changing where the money goes, which asks for the password again.
 *
 * Not because the seller is not signed in — they are — but because this is
 * the one seller action whose entire value to an attacker is that it
 * points a withdrawal somewhere new (§59).
 */
function DestinationForm({
    destination,
    currency,
}: {
    destination: SellerDestinationView | null;
    currency: string;
}) {
    const form = useForm({
        display_label: destination?.label ?? '',
        last4: '',
        country: destination?.country ?? '',
        current_password: '',
    });

    return (
        <section className="mb-10">
            <h2 className="mb-1 text-[20px]">Where your money goes</h2>
            <p className="mb-3 max-w-[62ch] text-[13px] text-[var(--vc-neutral-700)]">
                {destination === null
                    ? `Name the account you want to be paid into. We settle payouts by hand at the moment, so this is a label for our finance team rather than a connected bank account — we never hold your banking credentials.`
                    : `Currently ${destination.label}. Changing this applies to your next request, not to any payout already in progress.`}
            </p>

            <form
                className="grid max-w-[520px] gap-3"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post('/seller/payouts/destination', {
                        preserveScroll: true,
                        onSuccess: () => form.reset('current_password'),
                    });
                }}
            >
                <Field label="Account label" error={form.errors.display_label}>
                    {({ id, describedBy, invalid }) => (
                        <Input
                            id={id}
                            aria-describedby={describedBy}
                            value={form.data.display_label}
                            invalid={invalid}
                            onChange={(event) => form.setData('display_label', event.target.value)}
                        />
                    )}
                </Field>

                <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="Last four digits" hint="Optional" error={form.errors.last4}>
                        {({ id, describedBy, invalid }) => (
                            <Input
                                id={id}
                                aria-describedby={describedBy}
                                maxLength={4}
                                value={form.data.last4}
                                invalid={invalid}
                                onChange={(event) => form.setData('last4', event.target.value)}
                            />
                        )}
                    </Field>

                    <Field
                        label="Country"
                        hint={`Two letters. Paid in ${currency}.`}
                        error={form.errors.country}
                    >
                        {({ id, describedBy, invalid }) => (
                            <Input
                                id={id}
                                aria-describedby={describedBy}
                                maxLength={2}
                                value={form.data.country}
                                invalid={invalid}
                                onChange={(event) => form.setData('country', event.target.value)}
                            />
                        )}
                    </Field>
                </div>

                <Field
                    label="Your password"
                    hint="Confirming it is you before we change where money is sent."
                    error={form.errors.current_password}
                >
                    {({ id, describedBy, invalid }) => (
                        <Input
                            id={id}
                            aria-describedby={describedBy}
                            type="password"
                            autoComplete="current-password"
                            value={form.data.current_password}
                            invalid={invalid}
                            onChange={(event) =>
                                form.setData('current_password', event.target.value)
                            }
                        />
                    )}
                </Field>

                <div>
                    <Button type="submit" disabled={form.processing}>
                        {form.processing ? 'Saving…' : 'Save destination'}
                    </Button>
                </div>
            </form>
        </section>
    );
}
