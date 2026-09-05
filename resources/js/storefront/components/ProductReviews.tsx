import { router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '../../design-system/primitives/Button';
import { Field, Input, Textarea } from '../../design-system/primitives/Field';
import type { SharedPageProps } from '../../shared/types';

export interface ReviewRow {
    publicId: string;
    rating: number;
    title: string | null;
    body: string;
    verifiedPurchase: boolean;
    publishedAt: string | null;
    author: string;
    isMine: boolean;
}

export interface MyReview {
    publicId: string;
    rating: number;
    title: string | null;
    body: string;
    status: string;
    statusLabel: string;
    isPublic: boolean;
    isEditable: boolean;
    verifiedPurchase: boolean;
    moderationReason: string | null;
    publishedAt: string | null;
}

export interface RatingSummaryData {
    hasRating: boolean;
    average: number | null;
    reviewCount: number;
    verifiedCount: number;
    distribution: Record<string, number>;
}

export interface ReviewsData {
    data: ReviewRow[];
    page: number;
    perPage: number;
    total: number;
    lastPage: number;
    mine: MyReview | null;
    canWrite: {
        allowed: boolean;
        reason: string | null;
        message: string | null;
    };
}

/**
 * Reviews, on the canonical product page.
 *
 * Reviews belong to the product, not to a seller's offer of it (§3), so
 * there is exactly one list here however many sellers are competing above
 * it.
 *
 * The form posts a rating, a body and a title. It cannot post a verified
 * flag, an order or a status — those are not fields it has, and the
 * server would have nowhere to put them if it did (§4). The badge a
 * customer sees is the server's conclusion about their order history, not
 * a claim the browser made.
 *
 * Every review body is rendered as text through JSX. There is no
 * dangerouslySetInnerHTML anywhere in this file, and the server has
 * already stripped markup — two independent defences, because §13 says
 * not to assume React escaping is the only rendering context this text
 * will ever reach.
 */
export function ProductReviews({
    productPublicId,
    reviews,
    rating,
}: {
    productPublicId: string;
    reviews: ReviewsData;
    rating: RatingSummaryData;
}) {
    return (
        <section className="mt-16 max-w-[720px]" aria-labelledby="reviews-heading">
            <h2 id="reviews-heading" className="mb-6 text-[22px]">
                Reviews
            </h2>

            <RatingSummary rating={rating} />

            <MyReviewPanel productPublicId={productPublicId} reviews={reviews} />

            {reviews.total === 0 ? (
                <p className="mt-8 text-[15px] text-[var(--vc-neutral-600)]">
                    No reviews yet. Only customers who bought and received this product can write one.
                </p>
            ) : (
                <>
                    <ul className="mt-8 border-t-2 border-[var(--vc-text)]">
                        {reviews.data.map((review) => (
                            <li key={review.publicId} className="border-b border-[var(--vc-divider)] py-6">
                                <ReviewBody review={review} />
                            </li>
                        ))}
                    </ul>

                    <Pagination reviews={reviews} />
                </>
            )}
        </section>
    );
}

/**
 * The average, the count and the distribution.
 *
 * A product with no published reviews shows no average at all — never a
 * 0.0, which is not on the scale and would make an unreviewed product look
 * like a rejected one (§69).
 */
function RatingSummary({ rating }: { rating: RatingSummaryData }) {
    if (!rating.hasRating || rating.average === null) {
        return (
            <p className="text-[15px] text-[var(--vc-neutral-600)]">Not yet rated.</p>
        );
    }

    return (
        <div className="flex flex-wrap items-baseline gap-x-6 gap-y-2">
            <p className="text-[15px]">
                <span className="vc-tabular text-[28px]">{rating.average.toFixed(1)}</span>
                <span className="text-[var(--vc-neutral-600)]"> out of 5</span>
            </p>
            <p className="text-[14px] text-[var(--vc-neutral-600)]">
                {rating.reviewCount} {rating.reviewCount === 1 ? 'review' : 'reviews'}
                {rating.verifiedCount > 0 ? `, ${rating.verifiedCount} from verified purchases` : ''}
            </p>

            <dl className="mt-2 w-full max-w-[320px]">
                {[5, 4, 3, 2, 1].map((star) => {
                    const count = rating.distribution[String(star)] ?? 0;
                    const share = rating.reviewCount === 0 ? 0 : (count / rating.reviewCount) * 100;

                    return (
                        <div key={star} className="flex items-center gap-3 py-[3px] text-[13px]">
                            <dt className="w-[52px] shrink-0 text-[var(--vc-neutral-600)]">
                                {star} star
                            </dt>
                            <dd className="flex flex-1 items-center gap-2">
                                <span
                                    aria-hidden="true"
                                    className="h-[8px] flex-1 border border-[var(--vc-text)]"
                                >
                                    <span
                                        className="block h-full bg-[var(--vc-text)]"
                                        style={{ width: `${share}%` }}
                                    />
                                </span>
                                <span className="vc-tabular w-[32px] text-right">{count}</span>
                            </dd>
                        </div>
                    );
                })}
            </dl>
        </div>
    );
}

function ReviewBody({ review }: { review: ReviewRow }) {
    return (
        <article>
            <div className="mb-2 flex flex-wrap items-center gap-3 text-[13px]">
                <span className="vc-tabular font-semibold">{review.rating}/5</span>
                <span className="text-[var(--vc-neutral-600)]">{review.author}</span>
                {review.verifiedPurchase ? (
                    <span className="border border-[var(--vc-text)] px-2 py-[2px] text-[11px] tracking-[0.06em] uppercase">
                        Verified purchase
                    </span>
                ) : null}
                {review.isMine ? (
                    <span className="text-[12px] text-[var(--vc-neutral-600)]">Your review</span>
                ) : null}
            </div>

            {review.title ? <h3 className="mb-1 text-[16px] font-semibold">{review.title}</h3> : null}

            <p className="text-[15px] whitespace-pre-line">{review.body}</p>
        </article>
    );
}

/**
 * The customer's own review: writing it, editing it, or being told why
 * they cannot.
 */
function MyReviewPanel({
    productPublicId,
    reviews,
}: {
    productPublicId: string;
    reviews: ReviewsData;
}) {
    const [editing, setEditing] = useState(false);
    const mine = reviews.mine;

    if (mine !== null && !editing) {
        return (
            <div className="mt-8 border-2 border-[var(--vc-text)] p-5">
                <h3 className="mb-2 text-[16px] font-semibold">Your review</h3>

                {!mine.isPublic ? (
                    <p className="mb-3 text-[14px]">
                        This review is not shown on the page. {mine.moderationReason ?? ''}
                    </p>
                ) : null}

                <p className="mb-1 text-[14px]">
                    <span className="vc-tabular font-semibold">{mine.rating}/5</span>
                    {mine.title ? ` — ${mine.title}` : ''}
                </p>
                <p className="text-[15px] whitespace-pre-line">{mine.body}</p>

                <div className="mt-4 flex gap-3">
                    {mine.isEditable ? (
                        <Button type="button" variant="secondary" onClick={() => setEditing(true)}>
                            Edit
                        </Button>
                    ) : null}
                    <Button
                        type="button"
                        variant="secondary"
                        onClick={() => {
                            router.delete(`/reviews/${mine.publicId}`, { preserveScroll: true });
                        }}
                    >
                        Remove
                    </Button>
                </div>
            </div>
        );
    }

    if (mine !== null && editing) {
        return (
            <ReviewForm
                key="edit"
                heading="Edit your review"
                action={`/reviews/${mine.publicId}`}
                method="put"
                initial={{ rating: mine.rating, title: mine.title ?? '', body: mine.body }}
                onDone={() => setEditing(false)}
            />
        );
    }

    if (!reviews.canWrite.allowed) {
        return (
            <p className="mt-8 border-2 border-[var(--vc-divider)] px-4 py-3 text-[14px] text-[var(--vc-neutral-600)]">
                {reviews.canWrite.reason === 'sign_in' ? (
                    <>
                        <a href="/login" className="underline underline-offset-4">
                            Sign in
                        </a>{' '}
                        to review a product you bought.
                    </>
                ) : (
                    reviews.canWrite.message
                )}
            </p>
        );
    }

    return (
        <ReviewForm
            key="write"
            heading="Write a review"
            action="/reviews"
            method="post"
            productPublicId={productPublicId}
            initial={{ rating: 5, title: '', body: '' }}
        />
    );
}

function ReviewForm({
    heading,
    action,
    method,
    productPublicId,
    initial,
    onDone,
}: {
    heading: string;
    action: string;
    method: 'post' | 'put';
    productPublicId?: string;
    initial: { rating: number; title: string; body: string };
    onDone?: () => void;
}) {
    const form = useForm({
        ...(productPublicId === undefined ? {} : { product: productPublicId }),
        rating: initial.rating,
        title: initial.title,
        body: initial.body,
    });

    // A refusal is about the request, not about a field: "you have not
    // bought this" does not belong under the rating box. It arrives in the
    // shared error bag rather than in the form's own.
    const refusal = usePage<SharedPageProps>().props.errors.review;

    return (
        <form
            className="mt-8 border-2 border-[var(--vc-text)] p-5"
            onSubmit={(event) => {
                event.preventDefault();

                const options = {
                    preserveScroll: true,
                    onSuccess: () => onDone?.(),
                };

                if (method === 'put') {
                    form.put(action, options);

                    return;
                }

                form.post(action, options);
            }}
        >
            <h3 className="mb-4 text-[16px] font-semibold">{heading}</h3>

            {refusal ? (
                <p role="alert" className="mb-4 border-2 border-[var(--vc-text)] px-3 py-2 text-[14px]">
                    {refusal}
                </p>
            ) : null}

            <Field label="Rating" error={form.errors.rating}>
                {({ id, describedBy, invalid }) => (
                    <select
                        id={id}
                        aria-describedby={describedBy}
                        aria-invalid={invalid}
                        value={form.data.rating}
                        onChange={(event) => form.setData('rating', Number(event.target.value))}
                        className="w-full border-2 border-[var(--vc-text)] bg-[var(--vc-surface)] px-3 py-2 text-[15px]"
                    >
                        {[5, 4, 3, 2, 1].map((star) => (
                            <option key={star} value={star}>
                                {star} out of 5
                            </option>
                        ))}
                    </select>
                )}
            </Field>

            <Field label="Title" hint="Optional." error={form.errors.title}>
                {({ id, describedBy, invalid }) => (
                    <Input
                        id={id}
                        aria-describedby={describedBy}
                        aria-invalid={invalid}
                        value={form.data.title}
                        maxLength={120}
                        onChange={(event) => form.setData('title', event.target.value)}
                    />
                )}
            </Field>

            <Field
                label="Your review"
                hint="What you bought, how it arrived, whether it did what you expected."
                error={form.errors.body}
            >
                {({ id, describedBy, invalid }) => (
                    <Textarea
                        id={id}
                        aria-describedby={describedBy}
                        aria-invalid={invalid}
                        rows={6}
                        value={form.data.body}
                        maxLength={4000}
                        onChange={(event) => form.setData('body', event.target.value)}
                    />
                )}
            </Field>

            <div className="mt-4 flex gap-3">
                <Button type="submit" disabled={form.processing}>
                    {method === 'put' ? 'Save changes' : 'Publish review'}
                </Button>
                {onDone ? (
                    <Button type="button" variant="secondary" onClick={onDone}>
                        Cancel
                    </Button>
                ) : null}
            </div>
        </form>
    );
}

function Pagination({ reviews }: { reviews: ReviewsData }) {
    if (reviews.lastPage <= 1) {
        return null;
    }

    return (
        <nav aria-label="Reviews pages" className="mt-6 flex items-center gap-4 text-[14px]">
            <button
                type="button"
                disabled={reviews.page <= 1}
                onClick={() => go(reviews.page - 1)}
                className="underline underline-offset-4 disabled:opacity-40 disabled:no-underline"
            >
                Previous
            </button>
            <span className="vc-tabular text-[var(--vc-neutral-600)]">
                Page {reviews.page} of {reviews.lastPage}
            </span>
            <button
                type="button"
                disabled={reviews.page >= reviews.lastPage}
                onClick={() => go(reviews.page + 1)}
                className="underline underline-offset-4 disabled:opacity-40 disabled:no-underline"
            >
                Next
            </button>
        </nav>
    );
}

function go(page: number) {
    router.get(
        window.location.pathname,
        { reviews: page },
        { preserveScroll: true, preserveState: true, only: ['reviews'] },
    );
}
