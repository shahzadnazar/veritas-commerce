import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { AdminLayout } from '../../layouts/AdminLayout';
import { Button } from '../../../design-system/primitives/Button';
import { StatusBadge } from '../../../design-system/primitives/StatusBadge';
import { FlashBanner } from '../../../design-system/patterns/States';
import { ReasonDialog } from '../../../design-system/patterns/ReasonDialog';
import type { SharedPageProps } from '../../../shared/types';

interface ProductReviewProps extends SharedPageProps {
    product: {
        publicId: string;
        title: string;
        slug: string;
        description: string | null;
        status: string;
        moderationReason: string | null;
        brand: string | null;
        category: string | null;
        identifiers: Record<string, string>;
        proposedBy: string | null;
        submittedAt: string | null;
    };
    specifications: { name: string; value: string }[];
    variants: { name: string; options: Record<string, string> }[];
    media: { url: string; alt: string | null; state: string }[];
    history: {
        fromStatus: string | null;
        toStatus: string;
        actorType: string;
        reason: string | null;
        at: string;
    }[];
    can: { review: boolean; approve: boolean; reject: boolean; suspend: boolean };
}

const IDENTIFIER_LABELS: Record<string, string> = {
    gtin: 'GTIN',
    upc: 'UPC',
    ean: 'EAN',
    isbn: 'ISBN',
    mpn: 'MPN',
    model_number: 'Model number',
};

function Detail({ label, value }: { label: string; value: string | null }) {
    return (
        <div className="border-b border-[var(--vc-divider)] py-3">
            <dt className="text-[11px] tracking-[0.08em] text-[var(--vc-neutral-600)] uppercase">
                {label}
            </dt>
            <dd className="mt-1">{value ?? '—'}</dd>
        </div>
    );
}

/**
 * One proposal, with everything a moderator needs to decide it.
 *
 * The decision buttons a role cannot use are absent, but that is a
 * courtesy: the route middleware and the controller both check the same
 * permission again. Rejecting, requesting changes and suspending all
 * require a reason the server enforces, so all three go through the shared
 * dialog rather than a bare button.
 */
export default function ProductReview() {
    const { product, specifications, variants, media, history, can, flash } =
        usePage<ProductReviewProps>().props;
    const [dialog, setDialog] = useState<'reject' | 'changes' | 'suspend' | null>(null);

    const base = `/admin/catalogue/products/${product.publicId}`;

    // Only a proposal waiting on us can be decided. An approved product is
    // past that and offers a different action — publishing it — and a
    // published one can only be pulled back.
    const decidable = product.status === 'pending_review';
    const live = ['approved', 'published'].includes(product.status);
    const identifiers = Object.entries(product.identifiers);

    const approve = (publish: boolean) =>
        router.post(`${base}/approve`, { publish }, { preserveScroll: true });

    return (
        <AdminLayout
            title={product.title}
            actions={
                <>
                    {can.review && decidable ? (
                        <Button variant="secondary" onClick={() => setDialog('changes')}>
                            Request changes
                        </Button>
                    ) : null}

                    {can.reject && decidable ? (
                        <Button variant="destructive" onClick={() => setDialog('reject')}>
                            Reject
                        </Button>
                    ) : null}

                    {can.suspend && live ? (
                        <Button variant="destructive" onClick={() => setDialog('suspend')}>
                            Suspend
                        </Button>
                    ) : null}

                    {can.approve && decidable ? (
                        <Button variant="secondary" onClick={() => approve(false)}>
                            Accept, hold back
                        </Button>
                    ) : null}

                    {can.approve && decidable ? (
                        <Button variant="primary" onClick={() => approve(true)}>
                            Accept and publish
                        </Button>
                    ) : null}

                    {can.approve && product.status === 'approved' ? (
                        <Button variant="primary" onClick={() => approve(true)}>
                            Publish
                        </Button>
                    ) : null}
                </>
            }
        >
            <FlashBanner success={flash.success} error={flash.error} />

            <div className="mb-8 flex flex-wrap items-center gap-3">
                <StatusBadge domain="product" value={product.status} />
                <span className="text-[13px] text-[var(--vc-neutral-600)]">
                    Proposed by {product.proposedBy ?? 'the marketplace'}
                    {product.submittedAt ? ` · submitted ${product.submittedAt}` : ''}
                </span>
            </div>

            {product.moderationReason ? (
                <div className="mb-8 border-2 border-[var(--vc-accent)] p-4">
                    <p className="mb-1 text-[11px] tracking-[0.08em] text-[var(--vc-accent-800)] uppercase">
                        Reason on the record
                    </p>
                    <p>{product.moderationReason}</p>
                </div>
            ) : null}

            <div className="grid gap-10 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                <div>
                    <h2 className="mb-2 text-[20px]">Product</h2>
                    <dl className="mb-8">
                        <Detail label="Title" value={product.title} />
                        <Detail label="Slug" value={product.slug} />
                        <Detail label="Brand" value={product.brand} />
                        <Detail label="Category" value={product.category} />
                        <Detail label="Description" value={product.description} />
                    </dl>

                    <h2 className="mb-2 text-[20px]">Identifiers</h2>
                    {identifiers.length === 0 ? (
                        <p className="mb-8 text-[var(--vc-neutral-700)]">
                            None supplied. Not every product has one — a handmade item legitimately
                            carries no barcode.
                        </p>
                    ) : (
                        <dl className="mb-8">
                            {identifiers.map(([key, value]) => (
                                <Detail
                                    key={key}
                                    label={IDENTIFIER_LABELS[key] ?? key}
                                    value={value}
                                />
                            ))}
                        </dl>
                    )}

                    <h2 className="mb-2 text-[20px]">Specification</h2>
                    {specifications.length === 0 ? (
                        <p className="mb-8 text-[var(--vc-neutral-700)]">
                            This category asks for no attributes.
                        </p>
                    ) : (
                        <dl className="mb-8">
                            {specifications.map((specification) => (
                                <Detail
                                    key={specification.name}
                                    label={specification.name}
                                    value={specification.value}
                                />
                            ))}
                        </dl>
                    )}

                    <h2 className="mb-2 text-[20px]">Variants</h2>
                    {variants.length === 0 ? (
                        <p className="mb-8 text-[var(--vc-neutral-700)]">
                            Sold as a single item, with no variants.
                        </p>
                    ) : (
                        <ul className="mb-8 border-t-2 border-[var(--vc-text)]">
                            {variants.map((variant) => (
                                <li
                                    key={variant.name}
                                    className="border-b border-[var(--vc-divider)] py-3"
                                >
                                    <p className="font-semibold">{variant.name}</p>
                                    <p className="text-[12px] text-[var(--vc-neutral-600)]">
                                        {Object.entries(variant.options)
                                            .map(([key, value]) => `${key}: ${value}`)
                                            .join(' · ') || '—'}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    )}

                    <h2 className="mb-2 text-[20px]">Media</h2>
                    {media.length === 0 ? (
                        <p className="text-[var(--vc-neutral-700)]">No images were supplied.</p>
                    ) : (
                        <ul className="grid grid-cols-2 gap-4 sm:grid-cols-3">
                            {media.map((image) => (
                                <li key={image.url}>
                                    {/* Commerce photography stays in full colour. */}
                                    <img
                                        src={image.url}
                                        alt={image.alt ?? ''}
                                        className="w-full border-2 border-[var(--vc-text)] object-cover"
                                    />
                                    <p className="mt-1 text-[12px] text-[var(--vc-neutral-600)]">
                                        {image.state === 'ready'
                                            ? (image.alt ?? 'No alt text')
                                            : `Processing — ${image.state}`}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>

                <aside>
                    <h2 className="mb-2 text-[20px]">Moderation history</h2>
                    {history.length === 0 ? (
                        <p className="text-[var(--vc-neutral-700)]">
                            Nothing has happened to this product yet.
                        </p>
                    ) : (
                        <ol className="border-t-2 border-[var(--vc-text)]">
                            {history.map((entry, index) => (
                                <li
                                    key={index}
                                    className="border-b border-[var(--vc-divider)] py-3"
                                >
                                    <p className="text-[13px]">
                                        <span className="font-semibold">{entry.toStatus}</span>
                                        {entry.fromStatus ? ` from ${entry.fromStatus}` : ''} ·{' '}
                                        {entry.actorType}
                                    </p>
                                    <p className="text-[12px] text-[var(--vc-neutral-600)]">
                                        {entry.at}
                                    </p>
                                    {entry.reason ? (
                                        <p className="mt-1 text-[13px]">{entry.reason}</p>
                                    ) : null}
                                </li>
                            ))}
                        </ol>
                    )}
                </aside>
            </div>

            <ReasonDialog
                open={dialog === 'changes'}
                title="Ask the seller for changes?"
                consequence="The proposal goes back to the seller to correct and resend. It stays open, and this reason is what they will see."
                confirmLabel="Request changes"
                action={`${base}/request-changes`}
                onClose={() => setDialog(null)}
            />

            <ReasonDialog
                open={dialog === 'reject'}
                title="Reject this product?"
                consequence="The product is refused for the catalogue and no seller can list against it. Use this when the product does not belong here — if it only needs corrections, ask for changes instead."
                confirmLabel="Reject product"
                action={`${base}/reject`}
                onClose={() => setDialog(null)}
            />

            <ReasonDialog
                open={dialog === 'suspend'}
                title="Suspend this published product?"
                consequence="The product page and every seller's offer against it stop being visible immediately. Nothing is deleted, and the offers themselves are untouched — they simply have no published product to sell."
                confirmLabel="Suspend product"
                action={`${base}/suspend`}
                onClose={() => setDialog(null)}
            />
        </AdminLayout>
    );
}
