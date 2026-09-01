import { Link, router, useForm, usePage } from '@inertiajs/react';
import { SellerLayout } from '../../layouts/SellerLayout';
import { Button } from '../../../design-system/primitives/Button';
import { Field, Input, Select, Textarea } from '../../../design-system/primitives/Field';
import { FlashBanner } from '../../../design-system/patterns/States';
import type { SharedPageProps } from '../../../shared/types';

interface AttributeField {
    code: string;
    name: string;
    type: string;
    unit: string | null;
    required: boolean;
    options: { value: string; label: string }[];
}

interface ProposeProps extends SharedPageProps {
    /** What the same deterministic check would find on submit. */
    likelyDuplicates: { publicId: string; title: string; explanation: string }[];
    categories: { id: number; name: string }[];
    selectedCategoryId: number | null;
    attributes: AttributeField[];
    brands: { id: number; name: string }[];
    /** Set when correcting a proposal instead of making a new one. */
    editing: { publicId: string; status: string; moderationReason: string | null } | null;
    prefill: {
        title: string;
        description?: string;
        brand_id?: string;
        gtin?: string;
        upc?: string;
        ean?: string;
        isbn?: string;
        mpn?: string;
        model_number?: string;
        specifications?: Record<string, string>;
    };
    errors: Record<string, string>;
}

/**
 * Proposing a product the catalogue does not have.
 *
 * The specification fields come from the chosen category, so the form
 * cannot be built until one is picked — which is also what stops a seller
 * inventing their own specification vocabulary.
 */
export default function Propose() {
    const {
        likelyDuplicates,
        editing,
        categories,
        selectedCategoryId,
        attributes,
        brands,
        prefill,
        errors,
        flash,
    } = usePage<ProposeProps>().props;

    const form = useForm<{
        title: string;
        category_id: string;
        brand_id: string;
        new_brand: string;
        description: string;
        gtin: string;
        upc: string;
        ean: string;
        isbn: string;
        mpn: string;
        model_number: string;
        specifications: Record<string, string>;
    }>({
        title: prefill.title,
        category_id: selectedCategoryId ? String(selectedCategoryId) : '',
        brand_id: prefill.brand_id ?? '',
        new_brand: '',
        description: prefill.description ?? '',
        gtin: prefill.gtin ?? '',
        upc: prefill.upc ?? '',
        ean: prefill.ean ?? '',
        isbn: prefill.isbn ?? '',
        mpn: prefill.mpn ?? '',
        model_number: prefill.model_number ?? '',
        specifications: prefill.specifications ?? {},
    });

    return (
        <SellerLayout title={editing ? 'Correct your proposal' : 'Propose a product'}>
            <FlashBanner success={flash.success} error={flash.error} />

            <p className="mb-8 max-w-[62ch] text-[14px] text-[var(--vc-neutral-700)]">
                A moderator checks every proposal. Once accepted, the product belongs to the
                marketplace catalogue — other sellers can list against it, and your listing appears
                alongside theirs on one page.
            </p>

            {editing?.moderationReason ? (
                <div className="mb-8 max-w-[62ch] border-2 border-[var(--vc-accent)] p-4">
                    <p className="mb-1 text-[11px] tracking-[0.08em] text-[var(--vc-accent-800)] uppercase">
                        What the catalogue team asked for
                    </p>
                    <p>{editing.moderationReason}</p>
                </div>
            ) : null}

            {likelyDuplicates.length > 0 ? (
                <div className="mb-8 max-w-[62ch] border-2 border-[var(--vc-accent)] p-4">
                    <h2 className="mb-1 text-[16px]">The catalogue may already have this</h2>
                    <p className="mb-3 text-[13px] text-[var(--vc-neutral-700)]">
                        Listing against an existing product puts your offer on a page that already
                        has customers. Proposing a second copy of it does not.
                    </p>
                    <ul>
                        {likelyDuplicates.map((duplicate) => (
                            <li
                                key={duplicate.publicId}
                                className="border-t border-[var(--vc-divider)] py-2"
                            >
                                <Link
                                    href={`/seller/offers/create/${duplicate.publicId}`}
                                    className="font-semibold underline underline-offset-4"
                                >
                                    List against “{duplicate.title}”
                                </Link>
                                <p className="text-[12px] text-[var(--vc-neutral-600)]">
                                    {duplicate.explanation}
                                </p>
                            </li>
                        ))}
                    </ul>
                </div>
            ) : null}

            <form
                className="grid max-w-[900px] gap-10 md:grid-cols-2"
                onSubmit={(event) => {
                    event.preventDefault();
                    if (editing) {
                        form.patch(`/seller/products/${editing.publicId}`);
                    } else {
                        form.post('/seller/products');
                    }
                }}
            >
                <section className="flex flex-col gap-4">
                    <h2 className="text-[20px]">What it is</h2>

                    <Field label="Product name" error={form.errors.title}>
                        {({ id, describedBy, invalid }) => (
                            <Input
                                id={id}
                                required
                                aria-describedby={describedBy}
                                invalid={invalid}
                                value={form.data.title}
                                onChange={(event) => form.setData('title', event.target.value)}
                            />
                        )}
                    </Field>

                    <Field
                        label="Category"
                        error={form.errors.category_id}
                        hint="The category decides which specifications this product needs."
                    >
                        {({ id, describedBy, invalid }) => (
                            <Select
                                id={id}
                                required
                                aria-describedby={describedBy}
                                invalid={invalid}
                                value={form.data.category_id}
                                onChange={(event) => {
                                    form.setData('category_id', event.target.value);
                                    // The specification fields belong to
                                    // the category, so the form is rebuilt
                                    // server-side when one is chosen.
                                    router.get(
                                        '/seller/products/create',
                                        { category: event.target.value, title: form.data.title },
                                        { preserveState: false },
                                    );
                                }}
                            >
                                <option value="">Choose a category…</option>
                                {categories.map((category) => (
                                    <option key={category.id} value={category.id}>
                                        {category.name}
                                    </option>
                                ))}
                            </Select>
                        )}
                    </Field>

                    <Field label="Brand" error={form.errors.brand_id}>
                        {({ id, invalid }) => (
                            <Select
                                id={id}
                                invalid={invalid}
                                value={form.data.brand_id}
                                onChange={(event) => form.setData('brand_id', event.target.value)}
                            >
                                <option value="">Not listed / no brand</option>
                                {brands.map((brand) => (
                                    <option key={brand.id} value={brand.id}>
                                        {brand.name}
                                    </option>
                                ))}
                            </Select>
                        )}
                    </Field>

                    {form.data.brand_id === '' ? (
                        <Field
                            label="New brand name"
                            error={form.errors.new_brand}
                            hint="A brand you propose is reviewed before it appears anywhere."
                        >
                            {({ id, describedBy, invalid }) => (
                                <Input
                                    id={id}
                                    aria-describedby={describedBy}
                                    invalid={invalid}
                                    value={form.data.new_brand}
                                    onChange={(event) =>
                                        form.setData('new_brand', event.target.value)
                                    }
                                />
                            )}
                        </Field>
                    ) : null}

                    <Field label="Description" error={form.errors.description}>
                        {({ id, invalid }) => (
                            <Textarea
                                id={id}
                                invalid={invalid}
                                value={form.data.description}
                                onChange={(event) =>
                                    form.setData('description', event.target.value)
                                }
                            />
                        )}
                    </Field>
                </section>

                <section className="flex flex-col gap-4">
                    <h2 className="text-[20px]">Identifiers</h2>
                    <p className="text-[13px] text-[var(--vc-neutral-700)]">
                        A barcode is the surest way to avoid a duplicate. Handmade and own-brand
                        products often have none, which is fine — leave them blank.
                    </p>

                    {(
                        [
                            ['gtin', 'GTIN'],
                            ['ean', 'EAN'],
                            ['upc', 'UPC'],
                            ['isbn', 'ISBN'],
                            ['mpn', 'Manufacturer part number'],
                            ['model_number', 'Model number'],
                        ] as const
                    ).map(([key, label]) => (
                        <Field key={key} label={label} error={form.errors[key]}>
                            {({ id, invalid }) => (
                                <Input
                                    id={id}
                                    invalid={invalid}
                                    value={form.data[key]}
                                    onChange={(event) => form.setData(key, event.target.value)}
                                />
                            )}
                        </Field>
                    ))}
                </section>

                {attributes.length > 0 ? (
                    <section className="flex flex-col gap-4 md:col-span-2">
                        <h2 className="text-[20px]">Specifications</h2>

                        <div className="grid gap-4 md:grid-cols-2">
                            {attributes.map((attribute) => (
                                <Field
                                    key={attribute.code}
                                    label={
                                        attribute.name +
                                        (attribute.unit ? ` (${attribute.unit})` : '') +
                                        (attribute.required ? '' : ' — optional')
                                    }
                                    error={errors[`specifications.${attribute.code}`]}
                                >
                                    {({ id, describedBy, invalid }) =>
                                        attribute.options.length > 0 ? (
                                            <Select
                                                id={id}
                                                aria-describedby={describedBy}
                                                invalid={invalid}
                                                required={attribute.required}
                                                value={
                                                    form.data.specifications[attribute.code] ?? ''
                                                }
                                                onChange={(event) =>
                                                    form.setData('specifications', {
                                                        ...form.data.specifications,
                                                        [attribute.code]: event.target.value,
                                                    })
                                                }
                                            >
                                                <option value="">Choose…</option>
                                                {attribute.options.map((option) => (
                                                    <option key={option.value} value={option.value}>
                                                        {option.label}
                                                    </option>
                                                ))}
                                            </Select>
                                        ) : (
                                            <Input
                                                id={id}
                                                aria-describedby={describedBy}
                                                invalid={invalid}
                                                required={attribute.required}
                                                inputMode={
                                                    attribute.type === 'integer' ||
                                                    attribute.type === 'decimal'
                                                        ? 'decimal'
                                                        : undefined
                                                }
                                                value={
                                                    form.data.specifications[attribute.code] ?? ''
                                                }
                                                onChange={(event) =>
                                                    form.setData('specifications', {
                                                        ...form.data.specifications,
                                                        [attribute.code]: event.target.value,
                                                    })
                                                }
                                            />
                                        )
                                    }
                                </Field>
                            ))}
                        </div>
                    </section>
                ) : null}

                <div className="md:col-span-2">
                    <Button
                        type="submit"
                        variant="primary"
                        loading={form.processing}
                        loadingLabel="Submitting…"
                    >
                        {editing ? 'Send the corrections back' : 'Send to the catalogue team'}
                    </Button>
                </div>
            </form>
        </SellerLayout>
    );
}
