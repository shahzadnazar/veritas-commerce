import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { SellerLayout } from '../../layouts/SellerLayout';
import { Button } from '../../../design-system/primitives/Button';
import { Field, Input } from '../../../design-system/primitives/Field';
import { StatusBadge } from '../../../design-system/primitives/StatusBadge';
import { EmptyState, FlashBanner } from '../../../design-system/patterns/States';
import { Table } from '../../../design-system/patterns/Table';
import type { Column } from '../../../design-system/patterns/Table';
import type { SharedPageProps } from '../../../shared/types';

interface CatalogueMatch {
    publicId: string;
    title: string;
    brand: string | null;
    category: string | null;
    identifiers: Record<string, string>;
    alreadyListed: boolean;
}

interface Proposal {
    publicId: string;
    title: string;
    status: string;
    category: string | null;
    moderationReason: string | null;
    canEdit: boolean;
    canList: boolean;
}

interface SearchProps extends SharedPageProps {
    search: string;
    matches: CatalogueMatch[];
    proposals: Proposal[];
}

/**
 * Search first, propose second.
 *
 * The order is the product decision: a seller looking for a product they
 * stock should find the marketplace's entry and price against it. Creating
 * a new one is deliberately the second option, not the default.
 */
export default function Search() {
    const { search, matches, proposals, flash } = usePage<SearchProps>().props;
    const [term, setTerm] = useState(search);

    const proposalColumns: Column<Proposal>[] = [
        { key: 'title', header: 'Product', render: (row) => row.title },
        { key: 'category', header: 'Category', render: (row) => row.category ?? '—' },
        {
            key: 'status',
            header: 'Status',
            render: (row) => <StatusBadge domain="product" value={row.status} />,
        },
        {
            key: 'action',
            header: '',
            render: (row) =>
                row.canList ? (
                    <Link
                        href={`/seller/offers/create/${row.publicId}`}
                        className="text-[13px] underline underline-offset-4"
                    >
                        Set your price
                    </Link>
                ) : row.moderationReason ? (
                    <span className="text-[12px] text-[var(--vc-neutral-700)]">
                        {row.moderationReason}
                    </span>
                ) : null,
        },
    ];

    return (
        <SellerLayout title="Products">
            <FlashBanner success={flash.success} error={flash.error} />

            <section className="mb-10 max-w-[720px]">
                <h2 className="mb-1 text-[22px]">Find it in the marketplace catalogue</h2>
                <p className="mb-5 text-[14px] text-[var(--vc-neutral-700)]">
                    Most products are already here. Listing against the existing entry puts your
                    price on the same page as everyone else&rsquo;s, which is where customers
                    compare.
                </p>

                <form
                    className="flex flex-wrap items-end gap-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        router.get('/seller/products', { search: term }, { preserveState: true });
                    }}
                >
                    <div className="min-w-[280px] flex-1">
                        <Field label="Product name or barcode">
                            {({ id }) => (
                                <Input
                                    id={id}
                                    value={term}
                                    placeholder="Aeris cordless kettle, or 9780306406157"
                                    onChange={(event) => setTerm(event.target.value)}
                                />
                            )}
                        </Field>
                    </div>

                    <Button type="submit" variant="primary">
                        Search
                    </Button>
                </form>
            </section>

            {search !== '' ? (
                <section className="mb-12">
                    <h3 className="mb-4 text-[18px]">
                        {matches.length} {matches.length === 1 ? 'match' : 'matches'} for &ldquo;
                        {search}&rdquo;
                    </h3>

                    {matches.length === 0 ? (
                        <EmptyState
                            title="Nothing in the catalogue matches that"
                            body="If this product genuinely is not here, propose it. A moderator checks it before it goes live, and once accepted it belongs to the marketplace — other sellers can list against it too."
                            actions={
                                <Link
                                    href={`/seller/products/create?title=${encodeURIComponent(search)}`}
                                >
                                    <Button variant="primary">Propose this product</Button>
                                </Link>
                            }
                        />
                    ) : (
                        <ul className="border-t-2 border-[var(--vc-text)]">
                            {matches.map((match) => (
                                <li
                                    key={match.publicId}
                                    className="flex flex-wrap items-center gap-4 border-b border-[var(--vc-divider)] py-4"
                                >
                                    <span className="flex-1">
                                        <span className="block font-semibold">{match.title}</span>
                                        <span className="block text-[12px] text-[var(--vc-neutral-600)]">
                                            {[match.brand, match.category]
                                                .filter(Boolean)
                                                .join(' · ')}
                                            {Object.values(match.identifiers).length > 0
                                                ? ` · ${Object.values(match.identifiers)[0]}`
                                                : ''}
                                        </span>
                                    </span>

                                    {match.alreadyListed ? (
                                        <span className="text-[13px] text-[var(--vc-neutral-600)]">
                                            You already list this
                                        </span>
                                    ) : (
                                        <Link href={`/seller/offers/create/${match.publicId}`}>
                                            <Button variant="secondary">Set your price</Button>
                                        </Link>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            ) : null}

            <section>
                <div className="mb-4 flex flex-wrap items-center gap-4">
                    <h2 className="text-[22px]">Products you proposed</h2>
                    <Link
                        href="/seller/products/create"
                        className="text-[13px] underline underline-offset-4"
                    >
                        Propose a new one
                    </Link>
                </div>

                <Table
                    columns={proposalColumns}
                    rows={proposals}
                    rowKey={(row) => row.publicId}
                    caption="Products this store proposed to the marketplace catalogue"
                    empty={
                        <EmptyState
                            title="You have not proposed anything yet"
                            body="Search above first — most products are already in the catalogue, and listing against an existing entry is quicker than proposing a new one."
                        />
                    }
                />
            </section>
        </SellerLayout>
    );
}
