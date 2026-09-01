import { useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { SellerLayout } from '../../layouts/SellerLayout';
import { Button } from '../../../design-system/primitives/Button';
import { Field, Input, Select, Textarea } from '../../../design-system/primitives/Field';
import type { SharedPageProps } from '../../../shared/types';

interface OfferFormProps extends SharedPageProps {
    product: { publicId: string; title: string; brand: string | null };
    variants: { publicId: string; name: string }[];
    conditions: { value: string; label: string }[];
    offer: null;
    currency: string;
}

/** Money is entered as a decimal and sent as minor units. */
function toMinor(input: string): number {
    const parsed = Number.parseFloat(input.replace(/[^0-9.]/g, ''));

    return Number.isFinite(parsed) ? Math.round(parsed * 100) : 0;
}

export default function OfferForm() {
    const { product, variants, conditions, currency } = usePage<OfferFormProps>().props;

    const [price, setPrice] = useState('');
    const [compareAt, setCompareAt] = useState('');

    const form = useForm<{
        seller_sku: string;
        condition: string;
        price_minor: number;
        compare_at_price_minor: number | null;
        handling_days: number;
        seller_notes: string;
        variant_public_id: string | null;
    }>({
        seller_sku: '',
        condition: conditions[0]?.value ?? 'new',
        price_minor: 0,
        compare_at_price_minor: null,
        handling_days: 2,
        seller_notes: '',
        variant_public_id: null,
    });

    return (
        <SellerLayout title="Set your price">
            <div className="mb-8 max-w-[720px] border-2 border-[var(--vc-divider)] p-5">
                <p className="text-[11px] tracking-[0.08em] text-[var(--vc-neutral-600)] uppercase">
                    Marketplace product
                </p>
                <p className="text-[20px]">{product.title}</p>
                {product.brand ? (
                    <p className="text-[13px] text-[var(--vc-neutral-600)]">{product.brand}</p>
                ) : null}
                <p className="mt-3 max-w-[62ch] text-[13px] text-[var(--vc-neutral-700)]">
                    This is the marketplace&rsquo;s product, shared with every seller who lists it.
                    What you set below is <strong>your listing</strong>: your price, your condition,
                    your dispatch time.
                </p>
            </div>

            <form
                className="grid max-w-[720px] gap-6 md:grid-cols-2"
                onSubmit={(event) => {
                    event.preventDefault();
                    // Decimal in the field, minor units on the wire: money
                    // is converted once, at the boundary.
                    form.transform((data) => ({
                        ...data,
                        price_minor: toMinor(price),
                        compare_at_price_minor: compareAt === '' ? null : toMinor(compareAt),
                    }));
                    form.post(`/seller/offers/${product.publicId}`);
                }}
            >
                {variants.length > 0 ? (
                    <div className="md:col-span-2">
                        <Field
                            label="Which version are you selling?"
                            error={form.errors.variant_public_id}
                        >
                            {({ id, invalid }) => (
                                <Select
                                    id={id}
                                    invalid={invalid}
                                    value={form.data.variant_public_id ?? ''}
                                    onChange={(event) =>
                                        form.setData(
                                            'variant_public_id',
                                            event.target.value === '' ? null : event.target.value,
                                        )
                                    }
                                >
                                    <option value="">The product as a whole</option>
                                    {variants.map((variant) => (
                                        <option key={variant.publicId} value={variant.publicId}>
                                            {variant.name}
                                        </option>
                                    ))}
                                </Select>
                            )}
                        </Field>
                    </div>
                ) : null}

                <Field
                    label="Your SKU"
                    error={form.errors.seller_sku}
                    hint="Your own reference. Other sellers may use the same one."
                >
                    {({ id, describedBy, invalid }) => (
                        <Input
                            id={id}
                            required
                            aria-describedby={describedBy}
                            invalid={invalid}
                            value={form.data.seller_sku}
                            onChange={(event) => form.setData('seller_sku', event.target.value)}
                        />
                    )}
                </Field>

                <Field label="Condition" error={form.errors.condition}>
                    {({ id, invalid }) => (
                        <Select
                            id={id}
                            invalid={invalid}
                            value={form.data.condition}
                            onChange={(event) => form.setData('condition', event.target.value)}
                        >
                            {conditions.map((condition) => (
                                <option key={condition.value} value={condition.value}>
                                    {condition.label}
                                </option>
                            ))}
                        </Select>
                    )}
                </Field>

                <Field label={`Price (${currency})`} error={form.errors.price_minor}>
                    {({ id, describedBy, invalid }) => (
                        <Input
                            id={id}
                            required
                            inputMode="decimal"
                            placeholder="49.99"
                            aria-describedby={describedBy}
                            invalid={invalid}
                            value={price}
                            onChange={(event) => setPrice(event.target.value)}
                        />
                    )}
                </Field>

                <Field
                    label="Was (optional)"
                    error={form.errors.compare_at_price_minor}
                    hint="Shown struck through. Must be at or above your price."
                >
                    {({ id, describedBy, invalid }) => (
                        <Input
                            id={id}
                            inputMode="decimal"
                            aria-describedby={describedBy}
                            invalid={invalid}
                            value={compareAt}
                            onChange={(event) => setCompareAt(event.target.value)}
                        />
                    )}
                </Field>

                <Field label="Dispatches within (days)" error={form.errors.handling_days}>
                    {({ id, invalid }) => (
                        <Input
                            id={id}
                            type="number"
                            min={0}
                            max={30}
                            invalid={invalid}
                            value={form.data.handling_days}
                            onChange={(event) =>
                                form.setData('handling_days', Number(event.target.value))
                            }
                        />
                    )}
                </Field>

                <div className="md:col-span-2">
                    <Field label="Notes for customers (optional)" error={form.errors.seller_notes}>
                        {({ id, invalid }) => (
                            <Textarea
                                id={id}
                                invalid={invalid}
                                value={form.data.seller_notes}
                                onChange={(event) =>
                                    form.setData('seller_notes', event.target.value)
                                }
                            />
                        )}
                    </Field>
                </div>

                <div className="md:col-span-2">
                    <Button
                        type="submit"
                        variant="primary"
                        loading={form.processing}
                        loadingLabel="Saving…"
                    >
                        Save as draft
                    </Button>
                    <p className="mt-2 text-[12px] text-[var(--vc-neutral-600)]">
                        Saved listings start as drafts. You put them live from your listings page.
                    </p>
                </div>
            </form>
        </SellerLayout>
    );
}
