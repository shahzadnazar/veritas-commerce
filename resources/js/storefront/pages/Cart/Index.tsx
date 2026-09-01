import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { StorefrontLayout } from '../../../design-system/layout/StorefrontLayout';
import { Button } from '../../../design-system/primitives/Button';
import { IssueNotice, LineIssues } from '../../../design-system/patterns/IssueNotice';
import { QuantityStepper } from '../../../design-system/patterns/QuantityStepper';
import { EmptyState } from '../../../design-system/patterns/States';
import type { CartIssue, CartLine, CartView, IssueMessage } from '../../../shared/commerce';
import type { SharedPageProps } from '../../../shared/types';

interface CartPageProps extends SharedPageProps {
    cartView: CartView;
    maxLineQuantity: number;
    mergeNotices: CartIssue[];
    errors: Record<string, string>;
}

/**
 * The basket.
 *
 * Every number on this page came from the server, which rebuilt it from
 * the live offers a moment ago. React does not add up a subtotal here and
 * does not decide whether a line can be bought — both are facts about
 * inventory, and a page that computed them would be guessing at data it
 * cannot see.
 *
 * Grouped by seller because that is what the customer is actually doing:
 * buying from two or three businesses at once, which will become two or
 * three orders and two or three parcels. Showing that now is more honest
 * than revealing it on the confirmation screen.
 */
export default function CartIndex() {
    const page = usePage<CartPageProps>().props;
    const cart = page.cartView;

    // Which line is mid-request. One at a time: the server serialises
    // these anyway, and a spinner per row is what tells the customer
    // which number is not yet settled.
    const [pending, setPending] = useState<string | null>(null);

    const submit = (line: string, quantity: number) => {
        setPending(line);

        router.patch(
            `/cart/${encodeURIComponent(line)}`,
            { quantity },
            {
                preserveScroll: true,
                // Rapid clicks collapse into one in-flight request, so a
                // customer holding the + key cannot interleave two writes.
                onFinish: () => setPending(null),
            },
        );
    };

    const remove = (line: string) => {
        setPending(line);
        router.delete(`/cart/${encodeURIComponent(line)}`, {
            preserveScroll: true,
            onFinish: () => setPending(null),
        });
    };

    return (
        <StorefrontLayout title="Your basket">
            <h1 className="mb-8 text-[42px]">Your basket</h1>

            {page.mergeNotices.length > 0 ? (
                <IssueNotice
                    live="alert"
                    heading="Your basket changed when you signed in"
                    messages={page.mergeNotices.map(toMessage)}
                />
            ) : null}

            {page.errors.quantity ? (
                <p
                    role="alert"
                    className="mb-8 border-2 border-[var(--vc-accent)] px-4 py-3 text-[14px]"
                >
                    {page.errors.quantity}
                </p>
            ) : null}

            {cart.itemCount === 0 ? (
                <EmptyState
                    title="Nothing in your basket yet"
                    body="Browse the marketplace and add something from a seller you like the look of."
                    actions={
                        <Link href="/search">
                            <Button variant="primary">Browse the marketplace</Button>
                        </Link>
                    }
                />
            ) : (
                <div className="grid gap-14 lg:grid-cols-[1fr_320px]">
                    <div>
                        {cart.groups.map((group) => (
                            <section key={group.sellerAccountId} className="mb-10">
                                <h2 className="mb-1 text-[20px]">
                                    <Link href={`/stores/${group.storeSlug}`}>
                                        {group.storeName}
                                    </Link>
                                </h2>
                                <p className="mb-4 text-[13px] text-[var(--vc-neutral-600)]">
                                    Sold and delivered by {group.storeName} · {group.subtotal}
                                </p>

                                <ul className="border-t-2 border-[var(--vc-text)]">
                                    {group.lines.map((line) => (
                                        <CartRow
                                            key={line.lineIdentity}
                                            line={line}
                                            busy={pending === line.lineIdentity}
                                            onQuantity={(quantity) =>
                                                submit(line.lineIdentity, quantity)
                                            }
                                            onRemove={() => remove(line.lineIdentity)}
                                        />
                                    ))}
                                </ul>
                            </section>
                        ))}
                    </div>

                    <aside className="lg:sticky lg:top-8 lg:self-start">
                        <h2 className="mb-4 text-[20px]">Summary</h2>

                        <div className="flex items-baseline justify-between border-t-2 border-[var(--vc-text)] pt-3 text-[18px] font-semibold">
                            <span>Subtotal</span>
                            <span className="vc-tabular">{cart.subtotal}</span>
                        </div>
                        <p className="mt-1 mb-5 text-[12px] text-[var(--vc-neutral-600)]">
                            {cart.quantityCount} {cart.quantityCount === 1 ? 'item' : 'items'}{' '}
                            across {cart.groups.length}{' '}
                            {cart.groups.length === 1 ? 'seller' : 'sellers'}. Delivery and tax are
                            shown at checkout.
                        </p>

                        {cart.hasBlockingIssues ? (
                            <>
                                <Button variant="primary" block disabled>
                                    Continue to checkout
                                </Button>
                                <p
                                    role="status"
                                    className="mt-2 text-[12px] text-[var(--vc-accent-800)]"
                                >
                                    Resolve the items marked above before continuing.
                                </p>
                            </>
                        ) : (
                            <Link href="/checkout" className="block">
                                <Button variant="primary" block>
                                    Continue to checkout
                                </Button>
                            </Link>
                        )}
                    </aside>
                </div>
            )}
        </StorefrontLayout>
    );
}

/**
 * One line.
 *
 * Stacks on a phone and lays out in columns from `sm` up — a financial
 * table forced to scroll sideways on a 375px screen is not a mobile
 * solution, it is a desktop table with a scrollbar.
 */
function CartRow({
    line,
    busy,
    onQuantity,
    onRemove,
}: {
    line: CartLine;
    busy: boolean;
    onQuantity: (quantity: number) => void;
    onRemove: () => void;
}) {
    return (
        <li className="flex flex-col gap-4 border-b border-[var(--vc-divider)] py-5 sm:flex-row sm:items-start">
            <div className="h-[88px] w-[88px] shrink-0 bg-[var(--vc-surface)]">
                {line.imageUrl ? (
                    <img
                        src={line.imageUrl}
                        alt=""
                        className="h-full w-full object-cover"
                        loading="lazy"
                    />
                ) : null}
            </div>

            <div className="min-w-0 flex-1">
                {line.brand ? (
                    <p className="text-[12px] tracking-[0.06em] text-[var(--vc-neutral-600)] uppercase">
                        {line.brand}
                    </p>
                ) : null}

                <h3 className="text-[16px]">
                    <Link href={`/products/${line.productSlug}`}>{line.productTitle}</Link>
                </h3>

                {line.variantName ? (
                    <p className="text-[13px] text-[var(--vc-neutral-700)]">{line.variantName}</p>
                ) : null}

                <p className="mt-1 text-[13px] text-[var(--vc-neutral-700)]">
                    Sold by <Link href={`/stores/${line.storeSlug}`}>{line.storeName}</Link>
                    <span className="text-[var(--vc-neutral-600)]"> · {line.unitPrice} each</span>
                </p>

                {/*
                 * Availability in words as well as by disabling the
                 * control: a stepper that simply refuses to increment
                 * explains nothing.
                 */}
                <p className="mt-1 text-[12px] text-[var(--vc-neutral-600)]">
                    {line.available <= 0
                        ? 'Out of stock'
                        : `${line.available} available from this seller`}
                </p>

                <LineIssues issues={line.issues} />
            </div>

            <div className="flex items-center gap-4 sm:flex-col sm:items-end sm:gap-2">
                <QuantityStepper
                    value={line.quantity}
                    max={line.maxQuantity}
                    busy={busy}
                    disabled={line.available <= 0}
                    label={line.productTitle}
                    onChange={onQuantity}
                />

                <span className="vc-tabular text-[16px] font-semibold sm:mt-1">
                    {line.lineTotal}
                </span>

                <button
                    type="button"
                    onClick={onRemove}
                    disabled={busy}
                    className="text-[13px] underline underline-offset-4 disabled:opacity-45"
                >
                    Remove
                    <span className="sr-only"> {line.productTitle} from your basket</span>
                </button>
            </div>
        </li>
    );
}

/**
 * A raw issue, rendered as a sentence.
 *
 * The cart page's merge notices arrive as codes rather than as prose,
 * because they were stored in the session before there was a page to
 * write them for. Everything else on the checkout side gets its sentence
 * from the server.
 */
function toMessage(issue: CartIssue): IssueMessage {
    const detail: Record<CartIssue['code'], string> = {
        PRICE_CHANGED: 'The price of this item changed since you added it.',
        OUT_OF_STOCK: 'This item had sold out, so it was not added to your basket.',
        QUANTITY_REDUCED:
            issue.available === undefined
                ? 'The quantity was reduced to what the seller has available.'
                : `Quantity updated because only ${issue.available} ${
                      issue.available === 1 ? 'item is' : 'items are'
                  } currently available.`,
        OFFER_UNAVAILABLE: 'This offer is no longer available and was removed from your basket.',
        SELLER_UNAVAILABLE:
            'The seller is not trading at the moment, so this item was removed from your basket.',
        PRODUCT_UNAVAILABLE: 'This product has been withdrawn, so it was removed from your basket.',
        VARIANT_UNAVAILABLE: 'The option you chose is no longer offered, so it was removed.',
        CURRENCY_MISMATCH: 'This item is priced in a different currency and was removed.',
    };

    return {
        code: issue.code,
        blocking: issue.blocking,
        title: issue.label,
        detail: detail[issue.code],
    };
}
