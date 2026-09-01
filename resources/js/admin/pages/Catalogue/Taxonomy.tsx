import { useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { AdminLayout } from '../../layouts/AdminLayout';
import { Button } from '../../../design-system/primitives/Button';
import { Field, Input, Select } from '../../../design-system/primitives/Field';
import { FlashBanner } from '../../../design-system/patterns/States';
import type { SharedPageProps } from '../../../shared/types';

interface CategoryRow {
    id: number;
    publicId: string;
    name: string;
    slug: string;
    depth: number;
    parentId: number | null;
    isVisible: boolean;
    attributeCount: number;
}

interface AttributeRow {
    id: number;
    code: string;
    name: string;
    type: string;
    typeLabel: string;
    unit: string | null;
    isFilterable: boolean;
    isVariantDefining: boolean;
    optionCount: number;
}

interface BrandRow {
    publicId: string;
    name: string;
    slug: string;
    isApproved: boolean;
    proposedBySellerId: number | null;
}

interface TaxonomyProps extends SharedPageProps {
    categories: CategoryRow[];
    attributes: AttributeRow[];
    brands: BrandRow[];
    attributeTypes: { value: string; label: string; canDefineVariants: boolean }[];
    can: { categories: boolean; attributes: boolean; brands: boolean };
}

type Tab = 'categories' | 'attributes' | 'brands';

const TABS: { id: Tab; label: string }[] = [
    { id: 'categories', label: 'Categories' },
    { id: 'attributes', label: 'Attributes' },
    { id: 'brands', label: 'Brands' },
];

function slugify(value: string): string {
    return value
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

function CategoryForm({ categories }: { categories: CategoryRow[] }) {
    const form = useForm({ name: '', slug: '', parent_id: '', is_visible: true });

    return (
        <form
            className="mb-8 grid gap-4 border-2 border-[var(--vc-text)] p-5 sm:grid-cols-3"
            onSubmit={(event) => {
                event.preventDefault();
                form.post('/admin/catalogue/categories', {
                    preserveScroll: true,
                    onSuccess: () => form.reset(),
                });
            }}
        >
            <Field label="Name" error={form.errors.name}>
                {({ id, describedBy, invalid }) => (
                    <Input
                        id={id}
                        aria-describedby={describedBy}
                        invalid={invalid}
                        value={form.data.name}
                        onChange={(event) => {
                            const name = event.target.value;
                            form.setData((data) => ({
                                ...data,
                                name,
                                // The slug follows the name until someone
                                // edits it; after that it is theirs.
                                slug: data.slug === slugify(data.name) ? slugify(name) : data.slug,
                            }));
                        }}
                    />
                )}
            </Field>

            <Field
                label="Slug"
                error={form.errors.slug}
                hint="Part of the public URL. Changing it later costs the category its links."
            >
                {({ id, describedBy, invalid }) => (
                    <Input
                        id={id}
                        aria-describedby={describedBy}
                        invalid={invalid}
                        value={form.data.slug}
                        onChange={(event) => form.setData('slug', event.target.value)}
                    />
                )}
            </Field>

            <Field label="Parent" error={form.errors.parent_id}>
                {({ id, describedBy, invalid }) => (
                    <Select
                        id={id}
                        aria-describedby={describedBy}
                        invalid={invalid}
                        value={form.data.parent_id}
                        onChange={(event) => form.setData('parent_id', event.target.value)}
                    >
                        <option value="">Top level</option>
                        {categories.map((category) => (
                            <option key={category.id} value={String(category.id)}>
                                {'— '.repeat(category.depth)}
                                {category.name}
                            </option>
                        ))}
                    </Select>
                )}
            </Field>

            <div className="sm:col-span-3">
                <Button
                    type="submit"
                    variant="primary"
                    loading={form.processing}
                    loadingLabel="Creating…"
                >
                    Create category
                </Button>
            </div>
        </form>
    );
}

function AttributeForm({
    types,
}: {
    types: { value: string; label: string; canDefineVariants: boolean }[];
}) {
    const form = useForm({
        code: '',
        name: '',
        data_type: types[0]?.value ?? 'text',
        unit: '',
        is_filterable: false,
        is_searchable: false,
        is_variant_defining: false,
    });

    const selected = types.find((type) => type.value === form.data.data_type);

    return (
        <form
            className="mb-8 grid gap-4 border-2 border-[var(--vc-text)] p-5 sm:grid-cols-4"
            onSubmit={(event) => {
                event.preventDefault();
                form.post('/admin/catalogue/attributes', {
                    preserveScroll: true,
                    onSuccess: () => form.reset(),
                });
            }}
        >
            <Field
                label="Code"
                error={form.errors.code}
                hint="Lowercase and underscores. Permanent — it is what the data is stored under."
            >
                {({ id, describedBy, invalid }) => (
                    <Input
                        id={id}
                        aria-describedby={describedBy}
                        invalid={invalid}
                        value={form.data.code}
                        onChange={(event) => form.setData('code', event.target.value)}
                    />
                )}
            </Field>

            <Field label="Name" error={form.errors.name}>
                {({ id, describedBy, invalid }) => (
                    <Input
                        id={id}
                        aria-describedby={describedBy}
                        invalid={invalid}
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                    />
                )}
            </Field>

            <Field label="Type" error={form.errors.data_type}>
                {({ id, describedBy, invalid }) => (
                    <Select
                        id={id}
                        aria-describedby={describedBy}
                        invalid={invalid}
                        value={form.data.data_type}
                        onChange={(event) => {
                            const value = event.target.value;
                            const type = types.find((candidate) => candidate.value === value);
                            form.setData((data) => ({
                                ...data,
                                data_type: value,
                                is_variant_defining:
                                    type?.canDefineVariants === true
                                        ? data.is_variant_defining
                                        : false,
                            }));
                        }}
                    >
                        {types.map((type) => (
                            <option key={type.value} value={type.value}>
                                {type.label}
                            </option>
                        ))}
                    </Select>
                )}
            </Field>

            <Field label="Unit" error={form.errors.unit} hint="Optional, e.g. mm, g, W.">
                {({ id, describedBy, invalid }) => (
                    <Input
                        id={id}
                        aria-describedby={describedBy}
                        invalid={invalid}
                        value={form.data.unit}
                        onChange={(event) => form.setData('unit', event.target.value)}
                    />
                )}
            </Field>

            <fieldset className="sm:col-span-4">
                <legend className="mb-2 text-[12px] text-[var(--vc-neutral-700)]">
                    How the marketplace may use it
                </legend>
                <div className="flex flex-wrap gap-6">
                    <label className="flex items-center gap-2 text-[14px]">
                        <input
                            type="checkbox"
                            checked={form.data.is_filterable}
                            onChange={(event) =>
                                form.setData('is_filterable', event.target.checked)
                            }
                        />
                        Filterable on category pages
                    </label>

                    <label className="flex items-center gap-2 text-[14px]">
                        <input
                            type="checkbox"
                            checked={form.data.is_searchable}
                            onChange={(event) =>
                                form.setData('is_searchable', event.target.checked)
                            }
                        />
                        Searchable
                    </label>

                    <label className="flex items-center gap-2 text-[14px]">
                        <input
                            type="checkbox"
                            disabled={selected?.canDefineVariants !== true}
                            checked={form.data.is_variant_defining}
                            onChange={(event) =>
                                form.setData('is_variant_defining', event.target.checked)
                            }
                        />
                        Can distinguish variants
                        {selected?.canDefineVariants === true ? null : (
                            <span className="text-[12px] text-[var(--vc-neutral-600)]">
                                — not for a {selected?.label.toLowerCase() ?? 'this'} attribute
                            </span>
                        )}
                    </label>
                </div>
                {form.errors.is_variant_defining ? (
                    <p role="alert" className="mt-2 text-[12px] text-[var(--vc-accent-800)]">
                        {form.errors.is_variant_defining}
                    </p>
                ) : null}
            </fieldset>

            <div className="sm:col-span-4">
                <Button
                    type="submit"
                    variant="primary"
                    loading={form.processing}
                    loadingLabel="Creating…"
                >
                    Create attribute
                </Button>
            </div>
        </form>
    );
}

function AssignAttribute({
    categories,
    attributes,
}: {
    categories: CategoryRow[];
    attributes: AttributeRow[];
}) {
    const form = useForm({ category: '', attribute_id: '', is_required: false });

    return (
        <form
            className="mb-8 grid gap-4 border-2 border-[var(--vc-text)] p-5 sm:grid-cols-3"
            onSubmit={(event) => {
                event.preventDefault();
                if (form.data.category === '') {
                    return;
                }
                form.post(`/admin/catalogue/categories/${form.data.category}/attributes`, {
                    preserveScroll: true,
                    onSuccess: () => form.reset(),
                });
            }}
        >
            <Field label="Category">
                {({ id }) => (
                    <Select
                        id={id}
                        value={form.data.category}
                        onChange={(event) => form.setData('category', event.target.value)}
                    >
                        <option value="">Choose a category</option>
                        {categories.map((category) => (
                            <option key={category.publicId} value={category.publicId}>
                                {'— '.repeat(category.depth)}
                                {category.name}
                            </option>
                        ))}
                    </Select>
                )}
            </Field>

            <Field label="Attribute" error={form.errors.attribute_id}>
                {({ id, describedBy, invalid }) => (
                    <Select
                        id={id}
                        aria-describedby={describedBy}
                        invalid={invalid}
                        value={form.data.attribute_id}
                        onChange={(event) => form.setData('attribute_id', event.target.value)}
                    >
                        <option value="">Choose an attribute</option>
                        {attributes.map((attribute) => (
                            <option key={attribute.id} value={String(attribute.id)}>
                                {attribute.name}
                            </option>
                        ))}
                    </Select>
                )}
            </Field>

            <div className="flex flex-col justify-end gap-3">
                <label className="flex items-center gap-2 text-[14px]">
                    <input
                        type="checkbox"
                        checked={form.data.is_required}
                        onChange={(event) => form.setData('is_required', event.target.checked)}
                    />
                    Required for every product here
                </label>

                <Button
                    type="submit"
                    variant="secondary"
                    loading={form.processing}
                    loadingLabel="Assigning…"
                >
                    Assign
                </Button>
            </div>
        </form>
    );
}

/**
 * The taxonomy everything else lists against.
 *
 * Read by anyone who can see the catalogue; changed only by the roles that
 * hold the matching permission. When a role cannot change something the
 * forms are absent and it says why, rather than presenting a control that
 * would come back 403.
 */
export default function Taxonomy() {
    const { categories, attributes, brands, attributeTypes, can, flash } =
        usePage<TaxonomyProps>().props;
    const [tab, setTab] = useState<Tab>('categories');
    const brandForm = useForm({});

    return (
        <AdminLayout title="Taxonomy">
            <FlashBanner success={flash.success} error={flash.error} />

            <div role="tablist" aria-label="Taxonomy" className="mb-8 flex gap-2">
                {TABS.map((entry) => (
                    <button
                        key={entry.id}
                        type="button"
                        role="tab"
                        id={`tab-${entry.id}`}
                        aria-selected={tab === entry.id}
                        aria-controls={`panel-${entry.id}`}
                        onClick={() => setTab(entry.id)}
                        className={[
                            'min-h-[44px] border-2 px-4 text-[14px] font-semibold',
                            tab === entry.id
                                ? 'border-[var(--vc-text)] bg-[var(--vc-text)] text-[var(--vc-bg)]'
                                : 'border-[var(--vc-divider)] hover:bg-[var(--vc-surface)]',
                        ].join(' ')}
                    >
                        {entry.label}
                    </button>
                ))}
            </div>

            {tab === 'categories' ? (
                <section role="tabpanel" id="panel-categories" aria-labelledby="tab-categories">
                    {can.categories ? (
                        <>
                            <CategoryForm categories={categories} />
                            <h2 className="mb-2 text-[20px]">Assign an attribute to a category</h2>
                            <AssignAttribute categories={categories} attributes={attributes} />
                        </>
                    ) : (
                        <p className="mb-8 text-[var(--vc-neutral-700)]">
                            Your role can read the taxonomy but not change it.
                        </p>
                    )}

                    <ul className="border-t-2 border-[var(--vc-text)]">
                        {categories.map((category) => (
                            <li
                                key={category.publicId}
                                className="flex flex-wrap items-baseline gap-3 border-b border-[var(--vc-divider)] py-3"
                            >
                                <span
                                    className="flex-1 text-[14px]"
                                    style={{ paddingLeft: `${category.depth * 20}px` }}
                                >
                                    <span className="font-semibold">{category.name}</span>{' '}
                                    <span className="text-[12px] text-[var(--vc-neutral-600)]">
                                        /{category.slug}
                                    </span>
                                </span>
                                <span className="text-[12px] text-[var(--vc-neutral-600)]">
                                    {category.attributeCount} attribute
                                    {category.attributeCount === 1 ? '' : 's'}
                                    {category.isVisible ? '' : ' · hidden'}
                                </span>
                            </li>
                        ))}
                    </ul>
                </section>
            ) : null}

            {tab === 'attributes' ? (
                <section role="tabpanel" id="panel-attributes" aria-labelledby="tab-attributes">
                    {can.attributes ? (
                        <AttributeForm types={attributeTypes} />
                    ) : (
                        <p className="mb-8 text-[var(--vc-neutral-700)]">
                            Your role can read attributes but not define them.
                        </p>
                    )}

                    <ul className="border-t-2 border-[var(--vc-text)]">
                        {attributes.map((attribute) => (
                            <li
                                key={attribute.id}
                                className="flex flex-wrap items-baseline gap-3 border-b border-[var(--vc-divider)] py-3"
                            >
                                <span className="flex-1 text-[14px]">
                                    <span className="font-semibold">{attribute.name}</span>{' '}
                                    <span className="text-[12px] text-[var(--vc-neutral-600)]">
                                        {attribute.code}
                                    </span>
                                </span>
                                <span className="text-[12px] text-[var(--vc-neutral-600)]">
                                    {[
                                        attribute.typeLabel,
                                        attribute.unit,
                                        attribute.optionCount > 0
                                            ? `${attribute.optionCount} options`
                                            : null,
                                        attribute.isFilterable ? 'filterable' : null,
                                        attribute.isVariantDefining ? 'defines variants' : null,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                                </span>
                            </li>
                        ))}
                    </ul>
                </section>
            ) : null}

            {tab === 'brands' ? (
                <section role="tabpanel" id="panel-brands" aria-labelledby="tab-brands">
                    <p className="mb-6 max-w-prose text-[var(--vc-neutral-700)]">
                        A brand a seller proposed is usable straight away, but stays unapproved
                        until someone here confirms it is a real brand and not a second spelling of
                        one already listed.
                    </p>

                    <ul className="border-t-2 border-[var(--vc-text)]">
                        {brands.map((brand) => (
                            <li
                                key={brand.publicId}
                                className="flex flex-wrap items-center gap-3 border-b border-[var(--vc-divider)] py-3"
                            >
                                <span className="flex-1 text-[14px]">
                                    <span className="font-semibold">{brand.name}</span>{' '}
                                    <span className="text-[12px] text-[var(--vc-neutral-600)]">
                                        /{brand.slug}
                                    </span>
                                </span>

                                {brand.isApproved ? (
                                    <span className="text-[12px] text-[var(--vc-neutral-600)]">
                                        Approved
                                    </span>
                                ) : brand.proposedBySellerId !== null ? (
                                    <span className="text-[12px] text-[var(--vc-neutral-600)]">
                                        Proposed by a seller
                                    </span>
                                ) : null}

                                {can.brands && !brand.isApproved ? (
                                    <Button
                                        variant="secondary"
                                        loading={brandForm.processing}
                                        loadingLabel="Approving…"
                                        onClick={() =>
                                            brandForm.post(
                                                `/admin/catalogue/brands/${brand.publicId}/approve`,
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Approve brand
                                    </Button>
                                ) : null}
                            </li>
                        ))}
                    </ul>
                </section>
            ) : null}
        </AdminLayout>
    );
}
