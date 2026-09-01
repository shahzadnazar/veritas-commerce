import { router } from '@inertiajs/react';
import { Button } from '../../design-system/primitives/Button';
import { Field, Input, Select } from '../../design-system/primitives/Field';

export interface FacetOption {
    value: string;
    label: string;
    count: number;
    selected: boolean;
}

export interface AttributeFacet {
    code: string;
    name: string;
    unit: string | null;
    options: { value: string; label: string }[];
    selected: string[];
}

export interface Facets {
    brand?: FacetOption[];
    condition?: FacetOption[];
    availability?: FacetOption[];
    attributes?: AttributeFacet[];
}

export interface AppliedFilters {
    q: string;
    brand: string[];
    condition: string[];
    attributes: Record<string, string[]>;
    in_stock: boolean;
    min_price: string;
    max_price: string;
    sort: string;
    hasFilters: boolean;
}

type Params = Record<string, unknown>;

/**
 * The filter rail, driven entirely by the URL.
 *
 * Every control is a link in disguise: changing one pushes a new query
 * string and the server decides what it means. Nothing is filtered in the
 * browser, so a result set can be shared, bookmarked and crawled — which a
 * client-side filter cannot.
 *
 * The facets come from the server too: which attributes a category offers
 * is the catalogue's decision, not this component's.
 */
export function DiscoveryFilters({
    url,
    facets,
    applied,
}: {
    url: string;
    facets: Facets;
    applied: AppliedFilters;
}) {
    const push = (changes: Params) => {
        const next: Params = {
            q: applied.q,
            brand: applied.brand,
            condition: applied.condition,
            attributes: applied.attributes,
            in_stock: applied.in_stock,
            min_price: applied.min_price,
            max_price: applied.max_price,
            sort: applied.sort,
            ...changes,
            // Any filter change returns to page one: page four of the old
            // result set is meaningless against the new one.
            page: '1',
        };

        router.get(url, next as never, { preserveScroll: true, preserveState: true });
    };

    const toggle = (list: string[], value: string): string[] =>
        list.includes(value) ? list.filter((item) => item !== value) : [...list, value];

    return (
        <aside className="flex flex-col gap-8" aria-label="Filters">
            {applied.hasFilters ? (
                <Button variant="ghost" onClick={() => router.get(url, { q: applied.q } as never)}>
                    Clear all filters
                </Button>
            ) : null}

            <section>
                <h2 className="mb-3 text-[13px] tracking-[0.08em] uppercase">Price</h2>
                <form
                    className="flex items-end gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        const data = new FormData(event.currentTarget);
                        push({
                            min_price: String(data.get('min_price') ?? ''),
                            max_price: String(data.get('max_price') ?? ''),
                        });
                    }}
                >
                    <Field label="From">
                        {({ id }) => (
                            <Input
                                id={id}
                                name="min_price"
                                defaultValue={applied.min_price}
                                inputMode="decimal"
                            />
                        )}
                    </Field>
                    <Field label="To">
                        {({ id }) => (
                            <Input
                                id={id}
                                name="max_price"
                                defaultValue={applied.max_price}
                                inputMode="decimal"
                            />
                        )}
                    </Field>
                    <Button type="submit" variant="secondary">
                        Go
                    </Button>
                </form>
            </section>

            {facets.availability && facets.availability.length > 0 ? (
                <section>
                    <h2 className="mb-3 text-[13px] tracking-[0.08em] uppercase">Availability</h2>
                    {facets.availability.map((option) => (
                        <label
                            key={option.value}
                            className="flex items-center gap-2 py-1 text-[14px]"
                        >
                            <input
                                type="checkbox"
                                checked={option.selected}
                                onChange={() => push({ in_stock: !applied.in_stock })}
                            />
                            {option.label}
                            <span className="text-[12px] text-[var(--vc-neutral-600)]">
                                ({option.count})
                            </span>
                        </label>
                    ))}
                </section>
            ) : null}

            {facets.brand && facets.brand.length > 0 ? (
                <section>
                    <h2 className="mb-3 text-[13px] tracking-[0.08em] uppercase">Brand</h2>
                    {facets.brand.map((option) => (
                        <label
                            key={option.value}
                            className="flex items-center gap-2 py-1 text-[14px]"
                        >
                            <input
                                type="checkbox"
                                checked={option.selected}
                                onChange={() =>
                                    push({ brand: toggle(applied.brand, option.value) })
                                }
                            />
                            {option.label}
                            <span className="text-[12px] text-[var(--vc-neutral-600)]">
                                ({option.count})
                            </span>
                        </label>
                    ))}
                </section>
            ) : null}

            {facets.condition && facets.condition.length > 0 ? (
                <section>
                    <h2 className="mb-3 text-[13px] tracking-[0.08em] uppercase">Condition</h2>
                    {facets.condition.map((option) => (
                        <label
                            key={option.value}
                            className="flex items-center gap-2 py-1 text-[14px]"
                        >
                            <input
                                type="checkbox"
                                checked={option.selected}
                                onChange={() =>
                                    push({ condition: toggle(applied.condition, option.value) })
                                }
                            />
                            {option.label}
                            <span className="text-[12px] text-[var(--vc-neutral-600)]">
                                ({option.count})
                            </span>
                        </label>
                    ))}
                </section>
            ) : null}

            {(facets.attributes ?? []).map((facet) => (
                <section key={facet.code}>
                    <h2 className="mb-3 text-[13px] tracking-[0.08em] uppercase">
                        {facet.name}
                        {facet.unit ? ` (${facet.unit})` : ''}
                    </h2>
                    {facet.options.map((option) => (
                        <label
                            key={option.value}
                            className="flex items-center gap-2 py-1 text-[14px]"
                        >
                            <input
                                type="checkbox"
                                checked={(applied.attributes[facet.code] ?? []).includes(
                                    option.value,
                                )}
                                onChange={() =>
                                    push({
                                        attributes: {
                                            ...applied.attributes,
                                            [facet.code]: toggle(
                                                applied.attributes[facet.code] ?? [],
                                                option.value,
                                            ),
                                        },
                                    })
                                }
                            />
                            {option.label}
                        </label>
                    ))}
                </section>
            ))}
        </aside>
    );
}

/** The sort control, shared by every listing page. */
export function SortSelect({
    url,
    applied,
    sorts,
}: {
    url: string;
    applied: AppliedFilters;
    sorts: { value: string; label: string }[];
}) {
    return (
        <Field label="Sort by">
            {({ id }) => (
                <Select
                    id={id}
                    value={applied.sort}
                    onChange={(event) =>
                        router.get(
                            url,
                            {
                                q: applied.q,
                                brand: applied.brand,
                                condition: applied.condition,
                                attributes: applied.attributes,
                                in_stock: applied.in_stock,
                                min_price: applied.min_price,
                                max_price: applied.max_price,
                                sort: event.target.value,
                            } as never,
                            { preserveScroll: true },
                        )
                    }
                >
                    {sorts.map((sort) => (
                        <option key={sort.value} value={sort.value}>
                            {sort.label}
                        </option>
                    ))}
                </Select>
            )}
        </Field>
    );
}

/** Page links, server-side paged. */
export function Pagination({
    url,
    applied,
    page,
    lastPage,
}: {
    url: string;
    applied: AppliedFilters;
    page: number;
    lastPage: number;
}) {
    if (lastPage <= 1) {
        return null;
    }

    const go = (target: number) => {
        router.get(
            url,
            {
                q: applied.q,
                brand: applied.brand,
                condition: applied.condition,
                attributes: applied.attributes,
                in_stock: applied.in_stock,
                min_price: applied.min_price,
                max_price: applied.max_price,
                sort: applied.sort,
                page: String(target),
            } as never,
            { preserveScroll: false },
        );
    };

    return (
        <nav className="mt-12 flex items-center gap-4" aria-label="Pagination">
            <Button variant="secondary" disabled={page <= 1} onClick={() => go(page - 1)}>
                Previous
            </Button>
            <span className="vc-tabular text-[13px] text-[var(--vc-neutral-600)]">
                Page {page} of {lastPage}
            </span>
            <Button variant="secondary" disabled={page >= lastPage} onClick={() => go(page + 1)}>
                Next
            </Button>
        </nav>
    );
}
