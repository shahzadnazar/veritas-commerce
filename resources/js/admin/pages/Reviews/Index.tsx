import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { AdminLayout } from '../../layouts/AdminLayout';
import { EmptyState, FlashBanner } from '../../../design-system/patterns/States';
import { ReasonDialog } from '../../../design-system/patterns/ReasonDialog';
import { Button } from '../../../design-system/primitives/Button';
import { Field, Input } from '../../../design-system/primitives/Field';
import { StatusBadge } from '../../../design-system/primitives/StatusBadge';
import type { SharedPageProps } from '../../../shared/types';

interface ReviewRow {
    publicId: string;
    rating: number;
    title: string | null;
    body: string;
    status: string;
    verifiedPurchase: boolean;
    createdAt: string;
    publishedAt: string | null;
    moderationReason: string | null;
    wasModerated: boolean;
    product: { title: string; slug: string };
    author: string;
}

interface ReviewsIndexProps extends SharedPageProps {
    reviews: {
        data: ReviewRow[];
        page: number;
        perPage: number;
        total: number;
        lastPage: number;
    };
    counts: Record<string, number>;
    filters: { status: string | null; search: string | null };
    statuses: { value: string; label: string; requiresReason: boolean }[];
}

/**
 * Review moderation.
 *
 * A review queue, not an approval queue (§8). Verified reviews are already
 * live when a moderator sees them, so the default tab is "published" and
 * the job is deciding what should come down — not gating what goes up.
 *
 * Hide and reject each require a written reason, collected through the
 * same dialog every negative decision in the portal uses. The reason is
 * shown to the author, which is the point: a review that vanished without
 * explanation gets rewritten and resubmitted.
 */
export default function ReviewsIndex() {
    const { reviews, counts, filters, statuses, flash } = usePage<ReviewsIndexProps>().props;

    const [search, setSearch] = useState(filters.search ?? '');
    const [dialog, setDialog] = useState<{ review: ReviewRow; decision: 'hide' | 'reject' } | null>(
        null,
    );

    const applyFilters = (next: { status?: string | null; search?: string | null }) => {
        router.get(
            '/admin/reviews',
            {
                status: next.status === undefined ? filters.status : next.status,
                search: next.search === undefined ? filters.search : next.search,
            },
            { preserveState: true, replace: true },
        );
    };

    return (
        <AdminLayout title="Reviews">
            <FlashBanner success={flash.success} error={flash.error} />

            <div className="mb-6 flex flex-wrap items-end gap-4">
                <nav aria-label="Review status" className="flex flex-wrap gap-2">
                    <TabButton
                        label="All"
                        count={Object.values(counts).reduce((total, count) => total + count, 0)}
                        active={filters.status === null}
                        onClick={() => applyFilters({ status: null })}
                    />
                    {statuses.map((status) => (
                        <TabButton
                            key={status.value}
                            label={status.label}
                            count={counts[status.value] ?? 0}
                            active={filters.status === status.value}
                            onClick={() => applyFilters({ status: status.value })}
                        />
                    ))}
                </nav>

                <form
                    className="ml-auto flex items-end gap-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        applyFilters({ search });
                    }}
                >
                    <Field label="Search">
                        {({ id }) => (
                            <Input
                                id={id}
                                value={search}
                                placeholder="Review text or product"
                                onChange={(event) => setSearch(event.target.value)}
                            />
                        )}
                    </Field>
                    <Button type="submit" variant="secondary">
                        Search
                    </Button>
                </form>
            </div>

            {reviews.data.length === 0 ? (
                <EmptyState title="No reviews here" body="Nothing matches this filter." />
            ) : (
                <ul className="border-t-2 border-[var(--vc-text)]">
                    {reviews.data.map((review) => (
                        <li
                            key={review.publicId}
                            className="border-b border-[var(--vc-divider)] py-5"
                        >
                            <ReviewRowView
                                review={review}
                                onDecide={(decision) => setDialog({ review, decision })}
                            />
                        </li>
                    ))}
                </ul>
            )}

            {reviews.lastPage > 1 ? (
                <nav aria-label="Pages" className="mt-6 flex items-center gap-4 text-[14px]">
                    <button
                        type="button"
                        disabled={reviews.page <= 1}
                        onClick={() => page(reviews.page - 1, filters)}
                        className="underline underline-offset-4 disabled:opacity-40 disabled:no-underline"
                    >
                        Previous
                    </button>
                    <span className="vc-tabular text-[var(--vc-neutral-600)]">
                        Page {reviews.page} of {reviews.lastPage} — {reviews.total} reviews
                    </span>
                    <button
                        type="button"
                        disabled={reviews.page >= reviews.lastPage}
                        onClick={() => page(reviews.page + 1, filters)}
                        className="underline underline-offset-4 disabled:opacity-40 disabled:no-underline"
                    >
                        Next
                    </button>
                </nav>
            ) : null}

            <ReasonDialog
                open={dialog !== null}
                title={dialog?.decision === 'reject' ? 'Reject this review' : 'Hide this review'}
                consequence={
                    dialog?.decision === 'reject'
                        ? 'The review comes off the page and stops counting towards the rating. The author is told it broke the rules.'
                        : 'The review comes off the page and stops counting towards the rating. The author sees your reason and can edit it.'
                }
                confirmLabel={dialog?.decision === 'reject' ? 'Reject review' : 'Hide review'}
                action={
                    dialog === null
                        ? ''
                        : `/admin/reviews/${dialog.review.publicId}/${dialog.decision}`
                }
                onClose={() => setDialog(null)}
            />
        </AdminLayout>
    );
}

function ReviewRowView({
    review,
    onDecide,
}: {
    review: ReviewRow;
    onDecide: (decision: 'hide' | 'reject') => void;
}) {
    return (
        <article className="flex flex-col gap-3 md:flex-row md:items-start md:gap-8">
            <div className="min-w-0 flex-1">
                <div className="mb-2 flex flex-wrap items-center gap-3 text-[13px]">
                    <span className="vc-tabular font-semibold">{review.rating}/5</span>
                    <StatusBadge domain="product_review" value={review.status} />
                    {review.verifiedPurchase ? (
                        <span className="border border-[var(--vc-text)] px-2 py-[2px] text-[11px] tracking-[0.06em] uppercase">
                            Verified
                        </span>
                    ) : (
                        <span className="text-[12px] text-[var(--vc-neutral-600)]">Unverified</span>
                    )}
                    <span className="text-[var(--vc-neutral-600)]">{review.author}</span>
                    <a
                        href={`/products/${review.product.slug}`}
                        className="underline underline-offset-4"
                    >
                        {review.product.title}
                    </a>
                </div>

                {review.title ? (
                    <h2 className="mb-1 text-[15px] font-semibold">{review.title}</h2>
                ) : null}

                <p className="text-[14px] whitespace-pre-line">{review.body}</p>

                {review.moderationReason ? (
                    <p className="mt-2 text-[13px] text-[var(--vc-neutral-600)]">
                        Moderator note: {review.moderationReason}
                    </p>
                ) : null}
            </div>

            <div className="flex shrink-0 gap-2">
                {review.status === 'published' ? (
                    <>
                        <Button type="button" variant="secondary" onClick={() => onDecide('hide')}>
                            Hide
                        </Button>
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={() => onDecide('reject')}
                        >
                            Reject
                        </Button>
                    </>
                ) : null}

                {review.status === 'hidden' || review.status === 'rejected' ? (
                    <Button
                        type="button"
                        variant="secondary"
                        onClick={() =>
                            router.post(
                                `/admin/reviews/${review.publicId}/restore`,
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        Restore
                    </Button>
                ) : null}
            </div>
        </article>
    );
}

function TabButton({
    label,
    count,
    active,
    onClick,
}: {
    label: string;
    count: number;
    active: boolean;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-current={active ? 'page' : undefined}
            className={`border-2 px-3 py-2 text-[13px] ${
                active
                    ? 'border-[var(--vc-text)] bg-[var(--vc-text)] text-[var(--vc-bg)]'
                    : 'border-[var(--vc-divider)]'
            }`}
        >
            {label} <span className="vc-tabular">{count}</span>
        </button>
    );
}

function page(next: number, filters: { status: string | null; search: string | null }) {
    router.get(
        '/admin/reviews',
        { ...filters, page: next },
        { preserveState: true, preserveScroll: true },
    );
}
