import { usePage } from '@inertiajs/react';
import { AdminLayout } from '../../layouts/AdminLayout';
import { EmptyState } from '../../../design-system/patterns/States';
import type { SharedPageProps } from '../../../shared/types';

interface Term {
    query: string;
    count: number;
}

interface SearchHealthProps extends SharedPageProps {
    health: {
        days: number;
        searches: number;
        zeroResults: number;
        clicks: number;
        clickRate: number | null;
        topSearches: Term[];
        zeroResultSearches: Term[];
    };
}

function Figure({ label, value, hint }: { label: string; value: string; hint: string }) {
    return (
        <div className="border-2 border-[var(--vc-text)] p-4">
            <p className="text-[11px] tracking-[0.08em] text-[var(--vc-neutral-600)] uppercase">
                {label}
            </p>
            <p className="vc-tabular text-[28px]">{value}</p>
            <p className="text-[12px] text-[var(--vc-neutral-600)]">{hint}</p>
        </div>
    );
}

function TermList({ terms, empty }: { terms: Term[]; empty: string }) {
    if (terms.length === 0) {
        return <p className="text-[var(--vc-neutral-700)]">{empty}</p>;
    }

    return (
        <ol className="border-t-2 border-[var(--vc-text)]">
            {terms.map((term) => (
                <li
                    key={term.query}
                    className="flex items-baseline justify-between gap-4 border-b border-[var(--vc-divider)] py-2"
                >
                    <span className="text-[14px]">{term.query}</span>
                    <span className="vc-tabular text-[13px] text-[var(--vc-neutral-600)]">
                        {term.count}
                    </span>
                </li>
            ))}
        </ol>
    );
}

/**
 * Four numbers and two lists.
 *
 * The zero-result list is the one worth acting on: a search people repeat
 * that finds nothing is either a product the catalogue should carry or a
 * word the index should understand.
 */
export default function SearchHealth() {
    const { health } = usePage<SearchHealthProps>().props;

    return (
        <AdminLayout title="Search health">
            <p className="mb-8 text-[13px] text-[var(--vc-neutral-600)]">
                The last {health.days} days.
            </p>

            {health.searches === 0 ? (
                <EmptyState
                    title="No searches yet"
                    body="Once customers start searching, what they look for — and what they fail to find — appears here."
                />
            ) : (
                <>
                    <div className="mb-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                        <Figure
                            label="Searches"
                            value={String(health.searches)}
                            hint="Queries run by customers"
                        />
                        <Figure
                            label="Found nothing"
                            value={String(health.zeroResults)}
                            hint="Searches with no results"
                        />
                        <Figure
                            label="Result clicks"
                            value={String(health.clicks)}
                            hint="Results customers opened"
                        />
                        <Figure
                            label="Click rate"
                            value={health.clickRate === null ? '—' : `${health.clickRate}%`}
                            hint="Clicks per search"
                        />
                    </div>

                    <div className="grid gap-10 lg:grid-cols-2">
                        <section>
                            <h2 className="mb-2 text-[20px]">Most searched</h2>
                            <TermList terms={health.topSearches} empty="Nothing recorded yet." />
                        </section>

                        <section>
                            <h2 className="mb-2 text-[20px]">Searched, and found nothing</h2>
                            <p className="mb-3 max-w-[52ch] text-[13px] text-[var(--vc-neutral-700)]">
                                Each of these is either a product worth sourcing or a word the index
                                should learn.
                            </p>
                            <TermList
                                terms={health.zeroResultSearches}
                                empty="Every search returned something."
                            />
                        </section>
                    </div>
                </>
            )}
        </AdminLayout>
    );
}
