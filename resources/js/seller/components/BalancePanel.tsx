import type { PayoutEligibilityView, SellerFinancialPositionView } from '../../shared/commerce';

interface BalancePanelProps {
    position: SellerFinancialPositionView;
    eligibility: PayoutEligibilityView;
    nextReleaseAt?: string | null;
}

/**
 * The five states of a seller's money, kept apart on purpose.
 *
 * §74's warning is the whole design of this panel. Available and
 * withdrawable are different numbers whenever a payout is open, and a
 * screen that labelled every available dollar "available to withdraw"
 * would be telling the seller they can ask for money that is already
 * spoken for. So Available says what it is — cleared, and including what
 * is reserved — and Withdrawable is shown separately and emphasised,
 * because it is the one figure a seller can act on.
 *
 * Nothing here is computed. Every amount was formatted by the backend from
 * minor units, and `withdrawable` is what the domain returned rather than
 * a subtraction done in the browser (§80).
 */
export function BalancePanel({ position, eligibility, nextReleaseAt }: BalancePanelProps) {
    const hasReservation = position.reservedMinor > 0;

    return (
        <section aria-labelledby="balance-heading">
            <h2 id="balance-heading" className="mb-1 text-[20px]">
                Your money
            </h2>
            <p className="mb-3 max-w-[62ch] text-[13px] text-[var(--vc-neutral-700)]">
                Earnings are recorded when a customer pays, start clearing when you deliver, and
                become available once the clearing period ends.
            </p>

            <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <Amount
                    label="Pending"
                    amount={position.pending}
                    note="Paid for, not yet delivered."
                />
                <Amount
                    label="Clearing"
                    amount={position.clearing}
                    note={
                        nextReleaseAt
                            ? `Next release ${new Date(nextReleaseAt).toLocaleDateString()}.`
                            : 'Delivered, inside the clearing period.'
                    }
                />
                <Amount
                    label="Available"
                    amount={position.available}
                    note={
                        hasReservation
                            ? `Cleared, including ${position.reserved} held by a payout.`
                            : 'Cleared and not spoken for.'
                    }
                />
                <Amount
                    label="Reserved"
                    amount={position.reserved}
                    note={
                        hasReservation
                            ? 'Held by your open payout request.'
                            : 'Nothing is held right now.'
                    }
                />
            </dl>

            <div
                className={`mt-3 border-l-2 p-3 ${
                    position.isNegative
                        ? 'border-[var(--vc-accent)] bg-[var(--vc-accent-100)]'
                        : 'border-[var(--vc-text)]'
                }`}
            >
                <dl className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    <dt className="text-[11px] uppercase tracking-[0.08em] text-[var(--vc-neutral-700)]">
                        {position.isNegative ? 'Net available balance' : 'Available to withdraw'}
                    </dt>
                    <dd className="text-[24px] tabular-nums">{position.withdrawable}</dd>
                </dl>

                <p className="mt-1 max-w-[62ch] text-[13px] text-[var(--vc-neutral-700)]">
                    {/*
                     * The reason comes from the server, worded for a
                     * seller. The browser does not decide whether a payout
                     * is possible and does not compose the explanation.
                     */}
                    {eligibility.message}
                </p>

                {position.paidOutMinor > 0 ? (
                    <p className="mt-1 text-[13px] text-[var(--vc-neutral-700)]">
                        {position.paidOut} has been paid out to you in total.
                    </p>
                ) : null}
            </div>
        </section>
    );
}

function Amount({ label, amount, note }: { label: string; amount: string; note: string }) {
    return (
        <div className="border-t-2 border-[var(--vc-text)] pt-2">
            <dt className="text-[11px] uppercase tracking-[0.08em] text-[var(--vc-neutral-700)]">
                {label}
            </dt>
            <dd className="mt-1 text-[20px] tabular-nums">{amount}</dd>
            <dd className="mt-1 text-[13px] text-[var(--vc-neutral-700)]">{note}</dd>
        </div>
    );
}
