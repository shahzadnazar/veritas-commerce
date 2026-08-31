import { useForm, usePage } from '@inertiajs/react';
import { SellerLayout } from '../../layouts/SellerLayout';
import { Button } from '../../../design-system/primitives/Button';
import { Field, Input, Textarea } from '../../../design-system/primitives/Field';
import { FlashBanner } from '../../../design-system/patterns/States';
import type { SharedPageProps } from '../../../shared/types';

interface StoreEditProps extends SharedPageProps {
    store: {
        name: string;
        slug: string;
        description: string | null;
        supportEmail: string | null;
        supportPhone: string | null;
        shippingPolicy: string | null;
        returnPolicy: string | null;
        timezone: string | null;
        businessCity: string | null;
        businessState: string | null;
        businessCountry: string | null;
        isOpen: boolean;
        hasLogo: boolean;
        hasBanner: boolean;
    } | null;
    publicUrlBase: string;
}

export default function Edit() {
    const { store, publicUrlBase, flash } = usePage<StoreEditProps>().props;

    const form = useForm<{
        name: string;
        slug: string;
        description: string;
        support_email: string;
        support_phone: string;
        shipping_policy: string;
        return_policy: string;
        business_city: string;
        business_state: string;
        business_country: string;
        is_open: boolean;
        logo: File | null;
        banner: File | null;
    }>({
        name: store?.name ?? '',
        slug: store?.slug ?? '',
        description: store?.description ?? '',
        support_email: store?.supportEmail ?? '',
        support_phone: store?.supportPhone ?? '',
        shipping_policy: store?.shippingPolicy ?? '',
        return_policy: store?.returnPolicy ?? '',
        business_city: store?.businessCity ?? '',
        business_state: store?.businessState ?? '',
        business_country: store?.businessCountry ?? '',
        is_open: store?.isOpen ?? true,
        logo: null,
        banner: null,
    });

    return (
        <SellerLayout title={store === null ? 'Set up your store' : 'Store settings'}>
            <FlashBanner success={flash.success} error={flash.error} />

            <form
                className="grid max-w-[900px] gap-10 md:grid-cols-2"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post('/seller/store', { forceFormData: true, preserveScroll: true });
                }}
            >
                <section className="flex flex-col gap-4">
                    <h2 className="text-[20px]">Identity</h2>

                    <Field label="Store name" error={form.errors.name}>
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

                    <Field
                        label="Store address"
                        error={form.errors.slug}
                        hint={`${publicUrlBase}${form.data.slug || 'your-store'} — changing this keeps the old address working.`}
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

                    <Field label="Description" error={form.errors.description}>
                        {({ id, describedBy, invalid }) => (
                            <Textarea
                                id={id}
                                aria-describedby={describedBy}
                                invalid={invalid}
                                value={form.data.description}
                                onChange={(event) =>
                                    form.setData('description', event.target.value)
                                }
                            />
                        )}
                    </Field>

                    <h2 className="mt-4 text-[20px]">Branding</h2>

                    <Field
                        label="Logo"
                        error={form.errors.logo}
                        hint={
                            store?.hasLogo
                                ? 'A logo is set. Choose a file to replace it.'
                                : 'Square, at least 400×400.'
                        }
                    >
                        {({ id }) => (
                            <input
                                id={id}
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                className="text-[13px]"
                                onChange={(event) =>
                                    form.setData('logo', event.target.files?.[0] ?? null)
                                }
                            />
                        )}
                    </Field>

                    <Field
                        label="Banner"
                        error={form.errors.banner}
                        hint={
                            store?.hasBanner
                                ? 'A banner is set. Choose a file to replace it.'
                                : '1600×400.'
                        }
                    >
                        {({ id }) => (
                            <input
                                id={id}
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                className="text-[13px]"
                                onChange={(event) =>
                                    form.setData('banner', event.target.files?.[0] ?? null)
                                }
                            />
                        )}
                    </Field>
                </section>

                <section className="flex flex-col gap-4">
                    <h2 className="text-[20px]">Contact</h2>

                    <Field label="Support email" error={form.errors.support_email}>
                        {({ id, describedBy, invalid }) => (
                            <Input
                                id={id}
                                type="email"
                                aria-describedby={describedBy}
                                invalid={invalid}
                                value={form.data.support_email}
                                onChange={(event) =>
                                    form.setData('support_email', event.target.value)
                                }
                            />
                        )}
                    </Field>

                    <Field label="Support phone" error={form.errors.support_phone}>
                        {({ id, describedBy, invalid }) => (
                            <Input
                                id={id}
                                type="tel"
                                aria-describedby={describedBy}
                                invalid={invalid}
                                value={form.data.support_phone}
                                onChange={(event) =>
                                    form.setData('support_phone', event.target.value)
                                }
                            />
                        )}
                    </Field>

                    <div className="grid grid-cols-2 gap-4">
                        <Field label="Ships from — city" error={form.errors.business_city}>
                            {({ id, invalid }) => (
                                <Input
                                    id={id}
                                    invalid={invalid}
                                    value={form.data.business_city}
                                    onChange={(event) =>
                                        form.setData('business_city', event.target.value)
                                    }
                                />
                            )}
                        </Field>
                        <Field label="State" error={form.errors.business_state}>
                            {({ id, invalid }) => (
                                <Input
                                    id={id}
                                    invalid={invalid}
                                    value={form.data.business_state}
                                    onChange={(event) =>
                                        form.setData('business_state', event.target.value)
                                    }
                                />
                            )}
                        </Field>
                    </div>

                    <h2 className="mt-4 text-[20px]">Policies</h2>

                    <Field label="Shipping policy" error={form.errors.shipping_policy}>
                        {({ id, describedBy, invalid }) => (
                            <Textarea
                                id={id}
                                aria-describedby={describedBy}
                                invalid={invalid}
                                value={form.data.shipping_policy}
                                onChange={(event) =>
                                    form.setData('shipping_policy', event.target.value)
                                }
                            />
                        )}
                    </Field>

                    <Field label="Return policy" error={form.errors.return_policy}>
                        {({ id, describedBy, invalid }) => (
                            <Textarea
                                id={id}
                                aria-describedby={describedBy}
                                invalid={invalid}
                                value={form.data.return_policy}
                                onChange={(event) =>
                                    form.setData('return_policy', event.target.value)
                                }
                            />
                        )}
                    </Field>

                    <label className="flex items-center gap-2 text-[13px]">
                        <input
                            type="checkbox"
                            checked={form.data.is_open}
                            onChange={(event) => form.setData('is_open', event.target.checked)}
                        />
                        Open for orders
                    </label>

                    <Button
                        type="submit"
                        variant="primary"
                        loading={form.processing}
                        loadingLabel="Saving…"
                    >
                        Save store
                    </Button>
                </section>
            </form>
        </SellerLayout>
    );
}
