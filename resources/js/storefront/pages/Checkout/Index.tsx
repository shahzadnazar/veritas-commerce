import { Link, useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { StorefrontLayout } from '../../../design-system/layout/StorefrontLayout';
import { IssueNotice } from '../../../design-system/patterns/IssueNotice';
import { MoneyRow } from '../../../design-system/patterns/OrderPieces';
import { Button } from '../../../design-system/primitives/Button';
import { Field, Input, Select } from '../../../design-system/primitives/Field';
import type {
    CheckoutQuote,
    IssueMessage,
    SavedAddress,
    ShippingPolicy,
} from '../../../shared/commerce';
import type { SharedPageProps } from '../../../shared/types';

/** Exactly what the form posts. No money field exists here to tamper with. */
interface CheckoutFormData {
    idempotency_key: string;
    saved_address: string;
    email: string;
    name: string;
    line1: string;
    line2: string;
    city: string;
    state: string;
    postcode: string;
    country: string;
    phone: string;
    save_address: boolean;
    [key: string]: string | boolean;
}

interface CheckoutPageProps extends SharedPageProps {
    quote: CheckoutQuote;
    contact: { email: string | null; name: string | null; isGuest: boolean };
    addresses: SavedAddress[];
    issueMessages: IssueMessage[];
    shippingPolicy: ShippingPolicy;
}

/**
 * Review, then hand off to payment.
 *
 * Nothing priced on this page is posted back. The form carries an address,
 * an email and an idempotency key; the server rebuilds the quote from the
 * live offers when the button is pressed and refuses if it does not match
 * what the customer was shown. That is why there is no hidden total field
 * here — there is nowhere for a tampered price to enter.
 *
 * The button says "Continue to payment" because that is what it does. M4
 * produces an order awaiting payment; nothing has been charged and no card
 * details have been asked for, and a button labelled "Pay now" would be
 * claiming otherwise.
 */
export default function CheckoutIndex() {
    const page = usePage<CheckoutPageProps>().props;
    const quote = page.quote;
    const cart = quote.cart;

    const errorSummary = useRef<HTMLDivElement>(null);
    const [useSaved, setUseSaved] = useState(page.addresses.length > 0);

    const defaultAddress = page.addresses.find((address) => address.isDefault) ?? page.addresses[0];

    const form = useForm<CheckoutFormData>({
        // Generated once per mounted form, so a double-click, a refresh
        // and a retry after a timeout are all the same checkout.
        idempotency_key: newKey(),
        saved_address: defaultAddress?.publicId ?? '',
        email: page.contact.email ?? '',
        name: defaultAddress?.name ?? page.contact.name ?? '',
        line1: '',
        line2: '',
        city: '',
        state: '',
        postcode: '',
        country: 'GB',
        phone: '',
        save_address: false,
    });

    // Focus moves to the summary when a checkout is refused, so a keyboard
    // or screen-reader user is not left at the bottom of a long form
    // wondering why nothing happened.
    const errorCount = Object.keys(form.errors).length;
    useEffect(() => {
        if (errorCount > 0) {
            errorSummary.current?.focus();
        }
    }, [errorCount]);

    const blocking = quote.issues.filter((issue) => issue.blocking);

    return (
        <StorefrontLayout title="Checkout">
            <h1 className="mb-2 text-[42px]">Checkout</h1>
            <p className="mb-8 text-[var(--vc-neutral-700)]">
                Nothing is charged at this step. You will be taken to payment once your order is
                prepared.
            </p>

            {page.issueMessages.length > 0 ? (
                <IssueNotice
                    live={blocking.length > 0 ? 'alert' : 'status'}
                    heading={
                        blocking.length > 0
                            ? 'Some items need attention before you can continue'
                            : 'Your basket changed since you added these items'
                    }
                    messages={page.issueMessages}
                />
            ) : null}

            {errorCount > 0 ? (
                <div
                    ref={errorSummary}
                    tabIndex={-1}
                    role="alert"
                    className="mb-8 border-2 border-[var(--vc-accent)] px-5 py-4"
                >
                    <h2 className="mb-2 text-[16px]">We could not complete this checkout</h2>
                    <ul className="flex flex-col gap-1 text-[14px]">
                        {Object.entries(form.errors).map(([field, message]) => (
                            <li key={field}>{message}</li>
                        ))}
                    </ul>
                </div>
            ) : null}

            <form
                className="grid gap-14 lg:grid-cols-[1fr_360px]"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post('/checkout', { preserveScroll: true });
                }}
            >
                <div className="flex flex-col gap-10">
                    <section aria-labelledby="contact-heading">
                        <h2 id="contact-heading" className="mb-4 text-[22px]">
                            Contact
                        </h2>

                        <Field
                            label="Email"
                            error={form.errors.email}
                            hint="Your receipt and delivery updates go here."
                        >
                            {({ id, describedBy, invalid }) => (
                                <Input
                                    id={id}
                                    type="email"
                                    autoComplete="email"
                                    aria-describedby={describedBy}
                                    invalid={invalid}
                                    value={form.data.email}
                                    onChange={(event) => form.setData('email', event.target.value)}
                                />
                            )}
                        </Field>
                    </section>

                    <section aria-labelledby="address-heading">
                        <h2 id="address-heading" className="mb-4 text-[22px]">
                            Delivery address
                        </h2>

                        {page.addresses.length > 0 ? (
                            <div className="mb-4 flex flex-col gap-2">
                                <label className="flex items-center gap-2 text-[14px]">
                                    <input
                                        type="radio"
                                        name="address-mode"
                                        checked={useSaved}
                                        onChange={() => setUseSaved(true)}
                                    />
                                    Use a saved address
                                </label>
                                <label className="flex items-center gap-2 text-[14px]">
                                    <input
                                        type="radio"
                                        name="address-mode"
                                        checked={!useSaved}
                                        onChange={() => {
                                            setUseSaved(false);
                                            form.setData('saved_address', '');
                                        }}
                                    />
                                    Enter a new address
                                </label>
                            </div>
                        ) : null}

                        {useSaved && page.addresses.length > 0 ? (
                            <Field label="Saved address" error={form.errors.saved_address}>
                                {({ id, describedBy, invalid }) => (
                                    <Select
                                        id={id}
                                        aria-describedby={describedBy}
                                        invalid={invalid}
                                        value={form.data.saved_address}
                                        onChange={(event) =>
                                            form.setData('saved_address', event.target.value)
                                        }
                                    >
                                        {page.addresses.map((address) => (
                                            <option key={address.publicId} value={address.publicId}>
                                                {[
                                                    address.label,
                                                    address.name,
                                                    address.line1,
                                                    address.city,
                                                    address.postcode,
                                                ]
                                                    .filter(Boolean)
                                                    .join(' · ')}
                                            </option>
                                        ))}
                                    </Select>
                                )}
                            </Field>
                        ) : (
                            <div className="flex flex-col gap-4">
                                <AddressFields
                                    data={form.data}
                                    errors={form.errors}
                                    set={form.setData}
                                />
                                {!page.contact.isGuest ? (
                                    <label className="flex items-center gap-2 text-[14px]">
                                        <input
                                            type="checkbox"
                                            checked={form.data.save_address}
                                            onChange={(event) =>
                                                form.setData('save_address', event.target.checked)
                                            }
                                        />
                                        Save this address for next time
                                    </label>
                                ) : null}
                            </div>
                        )}
                    </section>

                    <section aria-labelledby="review-heading">
                        <h2 id="review-heading" className="mb-1 text-[22px]">
                            Your order
                        </h2>
                        <p className="mb-4 text-[13px] text-[var(--vc-neutral-600)]">
                            {cart.groups.length === 1
                                ? 'One seller, one delivery.'
                                : `${cart.groups.length} sellers, so ${cart.groups.length} separate deliveries.`}
                        </p>

                        {cart.groups.map((group) => (
                            <div key={group.sellerAccountId} className="mb-6">
                                <h3 className="mb-2 text-[16px]">{group.storeName}</h3>
                                <ul className="border-t border-[var(--vc-divider)]">
                                    {group.lines.map((line) => (
                                        <li
                                            key={line.lineIdentity}
                                            className="flex justify-between gap-4 border-b border-[var(--vc-divider)] py-3 text-[14px]"
                                        >
                                            <span>
                                                {line.productTitle}
                                                {line.variantName ? ` — ${line.variantName}` : ''}
                                                <span className="block text-[12px] text-[var(--vc-neutral-600)]">
                                                    {line.quantity} × {line.unitPrice}
                                                </span>
                                            </span>
                                            <span className="vc-tabular whitespace-nowrap">
                                                {line.lineTotal}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                                <p className="pt-2 text-right text-[13px] vc-tabular">
                                    Seller subtotal {group.subtotal}
                                </p>
                            </div>
                        ))}
                    </section>
                </div>

                <aside className="lg:sticky lg:top-8 lg:self-start">
                    <h2 className="mb-4 text-[20px]">Total</h2>

                    {/*
                     * Every figure is the server's, already formatted.
                     * React adds nothing up here — if these disagree the
                     * bug is in the quote, where it can be tested.
                     */}
                    <MoneyRow label="Items" value={quote.itemsTotal} />
                    <MoneyRow
                        label="Delivery"
                        value={quote.shippingTotalMinor === 0 ? 'Included' : quote.shippingTotal}
                        note={page.shippingPolicy.note}
                    />
                    <MoneyRow
                        label="Tax"
                        value={quote.taxTotalMinor === 0 ? 'Not calculated' : quote.taxTotal}
                        note={page.shippingPolicy.taxNote}
                    />
                    <MoneyRow label="Total" value={quote.grandTotal} strong />

                    <div className="mt-6">
                        <Button
                            type="submit"
                            variant="primary"
                            block
                            loading={form.processing}
                            loadingLabel="Preparing your order…"
                            disabled={!quote.buyable}
                        >
                            Continue to payment
                        </Button>
                    </div>

                    {!quote.buyable ? (
                        <p role="status" className="mt-2 text-[12px] text-[var(--vc-accent-800)]">
                            Resolve the items above before continuing.
                        </p>
                    ) : null}

                    <p className="mt-3 text-[12px] text-[var(--vc-neutral-600)]">
                        No payment is taken at this step.{' '}
                        <Link href="/cart" className="underline underline-offset-4">
                            Back to basket
                        </Link>
                    </p>
                </aside>
            </form>
        </StorefrontLayout>
    );
}

/**
 * The address form.
 *
 * Takes the three things it needs rather than the whole form object, so
 * it is typed end to end and a renamed field is a compile error rather
 * than a field that silently stops saving.
 */
function AddressFields({
    data,
    errors,
    set,
}: {
    data: CheckoutFormData;
    errors: Partial<Record<keyof CheckoutFormData, string>>;
    set: <K extends keyof CheckoutFormData>(key: K, value: CheckoutFormData[K]) => void;
}) {
    return (
        <>
            <Field label="Full name" error={errors.name}>
                {({ id, describedBy, invalid }) => (
                    <Input
                        id={id}
                        autoComplete="name"
                        aria-describedby={describedBy}
                        invalid={invalid}
                        value={data.name}
                        onChange={(event) => set('name', event.target.value)}
                    />
                )}
            </Field>

            <Field label="Address line 1" error={errors.line1}>
                {({ id, describedBy, invalid }) => (
                    <Input
                        id={id}
                        autoComplete="address-line1"
                        aria-describedby={describedBy}
                        invalid={invalid}
                        value={data.line1}
                        onChange={(event) => set('line1', event.target.value)}
                    />
                )}
            </Field>

            <Field label="Address line 2" error={errors.line2}>
                {({ id, describedBy, invalid }) => (
                    <Input
                        id={id}
                        autoComplete="address-line2"
                        aria-describedby={describedBy}
                        invalid={invalid}
                        value={data.line2}
                        onChange={(event) => set('line2', event.target.value)}
                    />
                )}
            </Field>

            <Field label="Town or city" error={errors.city}>
                {({ id, describedBy, invalid }) => (
                    <Input
                        id={id}
                        autoComplete="address-level2"
                        aria-describedby={describedBy}
                        invalid={invalid}
                        value={data.city}
                        onChange={(event) => set('city', event.target.value)}
                    />
                )}
            </Field>

            {/*
             * Optional, and labelled as such. Most of the world has no
             * state or province, and a required field here would lock out
             * whole countries — §33.
             */}
            <Field
                label="State or province (optional)"
                error={errors.state}
                hint="Leave blank if your country does not use one."
            >
                {({ id, describedBy, invalid }) => (
                    <Input
                        id={id}
                        autoComplete="address-level1"
                        aria-describedby={describedBy}
                        invalid={invalid}
                        value={data.state}
                        onChange={(event) => set('state', event.target.value)}
                    />
                )}
            </Field>

            <Field label="Postcode or ZIP" error={errors.postcode}>
                {({ id, describedBy, invalid }) => (
                    <Input
                        id={id}
                        autoComplete="postal-code"
                        aria-describedby={describedBy}
                        invalid={invalid}
                        value={data.postcode}
                        onChange={(event) => set('postcode', event.target.value)}
                    />
                )}
            </Field>

            <Field label="Country" error={errors.country} hint="Two-letter country code.">
                {({ id, describedBy, invalid }) => (
                    <Input
                        id={id}
                        autoComplete="country"
                        maxLength={2}
                        aria-describedby={describedBy}
                        invalid={invalid}
                        value={data.country}
                        onChange={(event) => set('country', event.target.value.toUpperCase())}
                    />
                )}
            </Field>

            <Field label="Phone (optional)" error={errors.phone}>
                {({ id, describedBy, invalid }) => (
                    <Input
                        id={id}
                        autoComplete="tel"
                        aria-describedby={describedBy}
                        invalid={invalid}
                        value={data.phone}
                        onChange={(event) => set('phone', event.target.value)}
                    />
                )}
            </Field>
        </>
    );
}

function newKey(): string {
    if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) {
        return crypto.randomUUID().replace(/-/g, '');
    }

    return `k${Date.now()}${Math.random().toString(36).slice(2, 10)}`;
}
