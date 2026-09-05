import { Link, usePage } from '@inertiajs/react';
import { SellerLayout } from '../layouts/SellerLayout';
import { BalancePanel } from '../components/BalancePanel';
import { Table, type Column } from '../../design-system/patterns/Table';
import { StatusBadge } from '../../design-system/primitives/StatusBadge';
import { EmptyState, FlashBanner } from '../../design-system/patterns/States';
import type {
    PayoutEligibilityView,
    SellerFinancialPositionView,
    SellerLedgerRowView,
    SellerStatementView,
} from '../../shared/commerce';
import type { SharedPageProps } from '../../shared/types';

interface EarningsProps extends SharedPageProps {
    position: SellerFinancialPositionView;
    statement: SellerStatementView;
    eligibility: PayoutEligibilityView;
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
 * The seller's financial statement. §35.
 *
 * Every row is a ledger entry, in the order the ledger recorded it, with
 * the running balance the ledger itself computed at the time. Nothing is
 * reconstructed from orders — a statement built from orders and a balance
 * built from the ledger disagree the moment a refund lands, and the seller
 * has no way to tell which is lying.
 */
export default function Earnings() {
    const { position, statement, eligibility, can, flash } = usePage<EarningsProps>().props;

    const columns: Column<SellerLedgerRowView>[] = [
        {
            key: 'date',
            header: 'Date',
            render: (row) => (
                <span className="whitespace-nowrap">
                    {new Date(row.occurredAt).toLocaleDateString()}
                </span>
            ),
        },
        {
            key: 'description',
            header: 'Description',
            render: (row) =>
                row.referenceKind === 'payout' && row.reference ? (
                    <Link
                        href={`/seller/payouts/${row.reference}`}
                        className="underline underline-offset-4"
                    >
                        {row.description}
                    </Link>
                ) : row.referenceKind === 'order' && row.reference ? (
                    <Link
                        href={`/seller/orders/${row.reference}`}
                        className="underline underline-offset-4"
                    >
                        {row.description}
                    </Link>
                ) : (
                    row.description
                ),
        },
        {
            key: 'credit',
            header: 'In',
            numeric: true,
            // An em dash, never $0.00: nothing came in on a debit row, and
            // a zero would claim a movement that did not happen.
            render: (row) => row.credit ?? '—',
        },
        {
            key: 'debit',
            header: 'Out',
            numeric: true,
            render: (row) => row.debit ?? '—',
        },
        {
            key: 'balance',
            header: 'Balance',
            numeric: true,
            render: (row) => row.balanceAfter,
        },
        {
            key: 'status',
            header: 'State',
            render: (row) => <StatusBadge domain="ledger_entry_status" value={row.status} />,
        },
    ];

    return (
        <SellerLayout
            title="Earnings"
            actions={
                can.viewPayouts ? (
                    <Link
                        href="/seller/payouts"
                        className="text-[13px] underline underline-offset-4"
                    >
                        Payouts
                    </Link>
                ) : undefined
            }
        >
            <FlashBanner success={flash.success} error={flash.error} />

            <div className="mb-10">
                <BalancePanel position={position} eligibility={eligibility} />
            </div>

            <section aria-labelledby="statement-heading">
                <h2 id="statement-heading" className="mb-1 text-[20px]">
                    Statement
                </h2>
                <p className="mb-3 text-[13px] text-[var(--vc-neutral-700)]">
                    Every movement of your money, newest first. {statement.total} entries.
                </p>

                <Table
                    caption="Seller financial statement"
                    columns={columns}
                    rows={statement.rows}
                    rowKey={(row) => row.id}
                    empty={
                        <EmptyState
                            title="Nothing here yet"
                            body="Your first sale will appear here as soon as a customer pays for it."
                        />
                    }
                />

                {statement.lastPage > 1 ? (
                    <nav
                        className="mt-3 flex items-center gap-3 text-[13px]"
                        aria-label="Statement pages"
                    >
                        {statement.page > 1 ? (
                            <Link
                                href={`/seller/earnings?page=${statement.page - 1}`}
                                className="underline underline-offset-4"
                            >
                                Newer
                            </Link>
                        ) : null}
                        <span className="text-[var(--vc-neutral-700)]">
                            Page {statement.page} of {statement.lastPage}
                        </span>
                        {statement.page < statement.lastPage ? (
                            <Link
                                href={`/seller/earnings?page=${statement.page + 1}`}
                                className="underline underline-offset-4"
                            >
                                Older
                            </Link>
                        ) : null}
                    </nav>
                ) : null}
            </section>
        </SellerLayout>
    );
}
