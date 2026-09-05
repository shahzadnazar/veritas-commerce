import { router, usePage } from '@inertiajs/react';
import { AdminLayout } from '../../layouts/AdminLayout';
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
    currency: string | null;
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

interface FunnelStep {
    key: string;
    label: string;
    value: number;
    rate: number | null;
}

interface ProductRow {
    productId: number;
    slug: string | null;
    title: string;
    views: number;
    cartAdds: number;
    unitsSold: number;
    purchases: number;
    grossMinor: number;
    gross: string;
    conversionRate: number | null;
}

interface SellerRow {
    publicId: string;
    name: string;
    orders: number;
    units: number;
    delivered: number;
    gross: string;
    refunds: string;
    earnings: string;
    deliveryRate: number | null;
}

interface SearchPhraseRow {
    phrase: string;
    searches: number;
    sessions: number;
    zeroResults: number;
    clicks: number;
    cartAdds: number;
    purchases: number;
    clickRate: number | null;
}

interface AnalyticsProps extends SharedPageProps {
    marketplace: {
        period: { value: string; label: string };
        periods: { value: string; label: string }[];
        currency: string;
        timezone: string;
        from: string | null;
        to: string | null;
        totals: MetricTotalData[];
        series: MetricSeriesData[];
        funnel: { steps: FunnelStep[] };
        coverage: { days: number; computed: number; missing: string[]; complete: boolean };
    };
    products: { topSellers: ProductRow[]; topViewed: ProductRow[] };
    search: {
        totals: {
            searches: number;
            zeroResults: number;
            clicks: number;
            purchases: number;
            clickRate: number | null;
            zeroResultRate: number | null;
        };
        topPhrases: SearchPhraseRow[];
        zeroResultPhrases: SearchPhraseRow[];
    };
    sellers: SellerRow[] | null;
    currencies: string[];
    filters: { period: string; currency: string };
}

/**
 * Marketplace analytics.
 *
 * Every number on this page was computed by `analytics:rebuild` from the
 * marketplace's own records, and the money among them was copied from M7's
 * definitions rather than recomputed here (§56). Nothing on the page can
 * change anything — there is no form, no action and no write route behind
 * it (§2).
 *
 * The coverage line at the top is deliberately prominent. A chart of
 * zeroes for days the rebuild never ran looks exactly like a marketplace
 * that stopped trading, and the difference is worth a sentence.
 */
export default function AnalyticsIndex() {
    const { marketplace, products, search, sellers, currencies, filters } =
        usePage<AnalyticsProps>().props;

    const change = (value: number | null) =>
        value === null ? '—' : `${value > 0 ? '+' : ''}${value.toFixed(1)}%`;

    const productColumns: Column<ProductRow>[] = [
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
        { key: 'views', header: 'Views', numeric: true, render: (row) => row.views.toLocaleString() },
        {
            key: 'cartAdds',
            header: 'Cart adds',
            numeric: true,
            render: (row) => row.cartAdds.toLocaleString(),
        },
        {
            key: 'units',
            header: 'Units',
            numeric: true,
            render: (row) => row.unitsSold.toLocaleString(),
        },
        { key: 'gross', header: 'Gross', numeric: true, render: (row) => row.gross },
        {
            key: 'conversion',
            header: 'View → order',
            numeric: true,
            // An em dash, never 0%: a product nobody saw has no conversion
            // rate, and 0% would read as a product people rejected.
            render: (row) => (row.conversionRate === null ? '—' : `${row.conversionRate}%`),
        },
    ];

    const phraseColumns: Column<SearchPhraseRow>[] = [
        { key: 'phrase', header: 'Phrase', render: (row) => row.phrase },
        {
            key: 'searches',
            header: 'Searches',
            numeric: true,
            render: (row) => row.searches.toLocaleString(),
        },
        {
            key: 'sessions',
            header: 'Visitors',
            numeric: true,
            render: (row) => row.sessions.toLocaleString(),
        },
        {
            key: 'clicks',
            header: 'Clicks',
            numeric: true,
            render: (row) => row.clicks.toLocaleString(),
        },
        {
            key: 'clickRate',
            header: 'Click rate',
            numeric: true,
            render: (row) => (row.clickRate === null ? '—' : `${row.clickRate}%`),
        },
    ];

    const sellerColumns: Column<SellerRow>[] = [
        { key: 'name', header: 'Seller', render: (row) => row.name },
        { key: 'orders', header: 'Orders', numeric: true, render: (row) => row.orders.toLocaleString() },
        { key: 'units', header: 'Units', numeric: true, render: (row) => row.units.toLocaleString() },
        { key: 'gross', header: 'Gross', numeric: true, render: (row) => row.gross },
        { key: 'refunds', header: 'Refunds', numeric: true, render: (row) => row.refunds },
        { key: 'earnings', header: 'Earnings', numeric: true, render: (row) => row.earnings },
        {
            key: 'delivery',
            header: 'Delivered',
            numeric: true,
            render: (row) => (row.deliveryRate === null ? '—' : `${row.deliveryRate}%`),
        },
    ];

    const apply = (next: Partial<{ period: string; currency: string }>) => {
        router.get('/admin/analytics', { ...filters, ...next }, { preserveState: true, replace: true });
    };

    return (
        <AdminLayout title="Analytics">
            <div className="mb-6 flex flex-wrap items-end gap-4">
                <Field label="Period">
                    {({ id }) => (
                        <Select
                            id={id}
                            value={filters.period}
                            onChange={(event) => apply({ period: event.target.value })}
                        >
                            {marketplace.periods.map((period) => (
                                <option key={period.value} value={period.value}>
                                    {period.label}
                                </option>
                            ))}
                        </Select>
                    )}
                </Field>

                <Field label="Currency">
                    {({ id }) => (
                        <Select
                            id={id}
                            value={filters.currency}
                            onChange={(event) => apply({ currency: event.target.value })}
                        >
                            {currencies.map((currency) => (
                                <option key={currency} value={currency}>
                                    {currency}
                                </option>
                            ))}
                        </Select>
                    )}
                </Field>

                <p className="ml-auto max-w-[42ch] text-[13px] text-[var(--vc-neutral-600)]">
                    {marketplace.from} to {marketplace.to}, {marketplace.timezone}. Money in{' '}
                    {marketplace.currency} only — currencies are never added together.
                </p>
            </div>

            {marketplace.coverage.complete ? null : (
                <p
                    role="status"
                    className="mb-6 border-2 border-[var(--vc-text)] px-4 py-3 text-[14px]"
                >
                    {marketplace.coverage.computed} of {marketplace.coverage.days} days have been
                    computed. The remaining days show as zero because <code>analytics:rebuild</code>{' '}
                    has not run for them — not because nothing happened.
                </p>
            )}

            <section aria-label="Headline figures" className="mb-10">
                <dl className="grid grid-cols-2 gap-x-8 gap-y-6 md:grid-cols-4">
                    {marketplace.totals.map((total) => (
                        <div key={total.key}>
                            <dt className="text-[12px] tracking-[0.06em] text-[var(--vc-neutral-600)] uppercase">
                                {total.label}
                            </dt>
                            <dd className="vc-tabular text-[26px]">{total.formatted}</dd>
                            <dd className="text-[12px] text-[var(--vc-neutral-600)]">
                                {change(total.changePercent)} on the previous period
                            </dd>
                        </div>
                    ))}
                </dl>
            </section>

            <section aria-label="Daily trend" className="mb-10">
                <h2 className="mb-4 text-[20px]">Daily</h2>
                <div className="grid gap-8 md:grid-cols-2">
                    {marketplace.series.map((series) => (
                        <Sparkline key={series.key} series={series} />
                    ))}
                </div>
            </section>

            <section aria-label="Funnel" className="mb-10 max-w-[640px]">
                <h2 className="mb-1 text-[20px]">Funnel</h2>
                <p className="mb-4 max-w-[60ch] text-[13px] text-[var(--vc-neutral-600)]">
                    Each rate is against the step above it and nothing else. The steps do not
                    multiply out to an overall rate, because visitors skip them.
                </p>
                <dl className="border-t-2 border-[var(--vc-text)]">
                    {marketplace.funnel.steps.map((step) => (
                        <div
                            key={step.key}
                            className="flex items-baseline gap-6 border-b border-[var(--vc-divider)] py-3 text-[14px]"
                        >
                            <dt className="w-[180px] shrink-0">{step.label}</dt>
                            <dd className="vc-tabular w-[120px] text-right">
                                {step.value.toLocaleString()}
                            </dd>
                            <dd className="vc-tabular text-[var(--vc-neutral-600)]">
                                {step.rate === null ? '—' : `${step.rate}%`}
                            </dd>
                        </div>
                    ))}
                </dl>
            </section>

            <section aria-label="Products" className="mb-10">
                <h2 className="mb-4 text-[20px]">Best sellers</h2>
                <Table
                    columns={productColumns}
                    rows={products.topSellers}
                    rowKey={(row) => row.productId}
                    empty={<p className="text-[14px] text-[var(--vc-neutral-600)]">Nothing sold in this period.</p>}
                />
            </section>

            <section aria-label="Most viewed" className="mb-10">
                <h2 className="mb-1 text-[20px]">Most viewed</h2>
                <p className="mb-4 max-w-[60ch] text-[13px] text-[var(--vc-neutral-600)]">
                    A product with many views and few orders has a price, a photograph or a stock
                    problem — none of which is visible from the sales report alone.
                </p>
                <Table
                    columns={productColumns}
                    rows={products.topViewed}
                    rowKey={(row) => row.productId}
                    empty={<p className="text-[14px] text-[var(--vc-neutral-600)]">Nothing was viewed in this period.</p>}
                />
            </section>

            <section aria-label="Search" className="mb-10">
                <h2 className="mb-4 text-[20px]">Search</h2>

                <dl className="mb-6 grid grid-cols-2 gap-x-8 gap-y-4 md:grid-cols-4">
                    <Figure label="Searches" value={search.totals.searches.toLocaleString()} />
                    <Figure label="Clicks" value={search.totals.clicks.toLocaleString()} />
                    <Figure
                        label="Click rate"
                        value={search.totals.clickRate === null ? '—' : `${search.totals.clickRate}%`}
                    />
                    <Figure
                        label="Found nothing"
                        value={
                            search.totals.zeroResultRate === null
                                ? '—'
                                : `${search.totals.zeroResultRate}%`
                        }
                    />
                </dl>

                <h3 className="mb-1 text-[16px] font-semibold">Phrases that never find anything</h3>
                <p className="mb-3 max-w-[60ch] text-[13px] text-[var(--vc-neutral-600)]">
                    The most actionable list here: each is either a product to stock or a synonym the
                    index should know.
                </p>
                <Table
                    columns={phraseColumns}
                    rows={search.zeroResultPhrases}
                    rowKey={(row) => row.phrase}
                    empty={<p className="text-[14px] text-[var(--vc-neutral-600)]">Every phrase found something.</p>}
                />

                <h3 className="mt-8 mb-3 text-[16px] font-semibold">Most searched</h3>
                <Table
                    columns={phraseColumns}
                    rows={search.topPhrases}
                    rowKey={(row) => row.phrase}
                    empty={<p className="text-[14px] text-[var(--vc-neutral-600)]">Nobody searched in this period.</p>}
                />
            </section>

            {sellers === null ? null : (
                <section aria-label="Sellers">
                    <h2 className="mb-4 text-[20px]">Sellers</h2>
                    <Table
                        columns={sellerColumns}
                        rows={sellers}
                        rowKey={(row) => row.publicId}
                        empty={<p className="text-[14px] text-[var(--vc-neutral-600)]">No seller traded in this period.</p>}
                    />
                </section>
            )}
        </AdminLayout>
    );
}

function Figure({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <dt className="text-[12px] tracking-[0.06em] text-[var(--vc-neutral-600)] uppercase">
                {label}
            </dt>
            <dd className="vc-tabular text-[22px]">{value}</dd>
        </div>
    );
}

/**
 * A daily series, drawn as bars.
 *
 * Deliberately not a charting library: the shape of "did it go up" is
 * legible in bars, and a dependency that ships 120kB to draw six sparklines
 * is a dependency the storefront pays for too.
 */
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
