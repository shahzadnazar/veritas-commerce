import { router, usePage } from '@inertiajs/react';
import { SellerLayout } from '../../layouts/SellerLayout';
import { Table, type Column } from '../../../design-system/patterns/Table';
import { Field, Select } from '../../../design-system/primitives/Field';
import type { SharedPageProps } from '../../../shared/types';

interface MetricTotalData {
    key: string;
    label: string;
    value: number;
    formatted: string;
    previous: number | null;
    changePercent: number | null;
    isMoney: boolean;
}

interface MetricSeriesData {
    key: string;
    label: string;
    days: string[];
    values: number[];
    total: number;
    peak: number;
    isMoney: boolean;
}

interface TopProductRow {
    productId: number;
    slug: string | null;
    title: string;
    units: number;
    gross: string;
    earnings: string;
}

interface SellerAnalyticsProps extends SharedPageProps {
    analytics: {
        period: { value: string; label: string };
        periods: { value: string; label: string }[];
        currency: string;
        timezone: string;
        from: string | null;
        to: string | null;
        totals: MetricTotalData[];
        series: MetricSeriesData[];
        topProducts: TopProductRow[];
    };
    filters: { period: string };
}

/**
 * The store's own performance.
 *
 * Every figure is this seller's: their orders, their units, their
 * earnings, their listings. Nothing on this page is a marketplace-wide
 * total, and the best-sellers table is built from this store's own order
 * items rather than from the marketplace product rollup — showing a seller
 * the marketplace's number for a product they happen to list would be
 * handing them their competitors' volume (§52).
 *
 * Earnings come from the seller ledger, which is what the finance page
 * pays out against. This page reports it; it does not compute it.
 */
export default function SellerAnalytics() {
    const { analytics, filters } = usePage<SellerAnalyticsProps>().props;

    const columns: Column<TopProductRow>[] = [
        {
            key: 'title',
            header: 'Product',
            render: (row) =>
                row.slug === null ? (
                    row.title
                ) : (
                    <a href={`/products/${row.slug}`} className="underline underline-offset-4">
                        {row.title}
                    </a>
                ),
        },
        {
            key: 'units',
            header: 'Units',
            numeric: true,
            render: (row) => row.units.toLocaleString(),
        },
        { key: 'gross', header: 'Gross', numeric: true, render: (row) => row.gross },
        { key: 'earnings', header: 'Your earnings', numeric: true, render: (row) => row.earnings },
    ];

    return (
        <SellerLayout title="Analytics">
            <div className="mb-6 flex flex-wrap items-end gap-4">
                <Field label="Period">
                    {({ id }) => (
                        <Select
                            id={id}
                            value={filters.period}
                            onChange={(event) =>
                                router.get(
                                    '/seller/analytics',
                                    { period: event.target.value },
                                    { preserveState: true, replace: true },
                                )
                            }
                        >
                            {analytics.periods.map((period) => (
                                <option key={period.value} value={period.value}>
                                    {period.label}
                                </option>
                            ))}
                        </Select>
                    )}
                </Field>

                <p className="ml-auto text-[13px] text-[var(--vc-neutral-600)]">
                    {analytics.from} to {analytics.to}, {analytics.timezone}. Figures in{' '}
                    {analytics.currency}.
                </p>
            </div>

            <section aria-label="Headline figures" className="mb-10">
                <dl className="grid grid-cols-2 gap-x-8 gap-y-6 md:grid-cols-4">
                    {analytics.totals.map((total) => (
                        <div key={total.key}>
                            <dt className="text-[12px] tracking-[0.06em] text-[var(--vc-neutral-600)] uppercase">
                                {total.label}
                            </dt>
                            <dd className="vc-tabular text-[26px]">{total.formatted}</dd>
                            <dd className="text-[12px] text-[var(--vc-neutral-600)]">
                                {total.changePercent === null
                                    ? 'No comparable period'
                                    : `${total.changePercent > 0 ? '+' : ''}${total.changePercent.toFixed(1)}% on the previous period`}
                            </dd>
                        </div>
                    ))}
                </dl>
            </section>

            <section aria-label="Daily trend" className="mb-10">
                <h2 className="mb-4 text-[20px]">Daily</h2>
                <div className="grid gap-8 md:grid-cols-2">
                    {analytics.series.map((series) => (
                        <Sparkline key={series.key} series={series} />
                    ))}
                </div>
            </section>

            <section aria-label="Best sellers">
                <h2 className="mb-4 text-[20px]">Your best sellers</h2>
                <Table
                    columns={columns}
                    rows={analytics.topProducts}
                    rowKey={(row) => row.productId}
                    empty={
                        <p className="text-[14px] text-[var(--vc-neutral-600)]">
                            Nothing sold in this period.
                        </p>
                    }
                />
            </section>
        </SellerLayout>
    );
}

function Sparkline({ series }: { series: MetricSeriesData }) {
    const peak = Math.max(series.peak, 1);

    return (
        <figure>
            <figcaption className="mb-2 flex items-baseline justify-between text-[13px]">
                <span className="font-semibold">{series.label}</span>
                <span className="vc-tabular text-[var(--vc-neutral-600)]">
                    {series.total.toLocaleString()} total
                </span>
            </figcaption>

            <div
                className="flex h-[72px] items-end gap-[2px] border-b-2 border-[var(--vc-text)]"
                role="img"
                aria-label={`${series.label}, ${series.days.length} days, peak ${series.peak}`}
            >
                {series.values.map((value, index) => (
                    <span
                        key={series.days[index] ?? index}
                        title={`${series.days[index] ?? ''}: ${value.toLocaleString()}`}
                        className="flex-1 bg-[var(--vc-text)]"
                        style={{ height: `${Math.max(1, (value / peak) * 100)}%` }}
                    />
                ))}
            </div>
        </figure>
    );
}
