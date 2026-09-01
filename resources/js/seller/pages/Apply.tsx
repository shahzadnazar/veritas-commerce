import { useForm, usePage } from '@inertiajs/react';
import { OnboardingLayout } from '../layouts/OnboardingLayout';
import { Button } from '../../design-system/primitives/Button';
import { Field, Input, Textarea } from '../../design-system/primitives/Field';
import { StatusBadge } from '../../design-system/primitives/StatusBadge';
import { DocumentUploader } from '../../design-system/patterns/DocumentUploader';
import { FlashBanner } from '../../design-system/patterns/States';
import type { SharedPageProps } from '../../shared/types';

interface ApplicationValues {
    legal_name: string | null;
    trading_name: string | null;
    business_type: string | null;
    address_line1: string | null;
    address_city: string | null;
    address_state: string | null;
    address_postcode: string | null;
    contact_name: string | null;
    contact_email: string | null;
    contact_phone: string | null;
    website: string | null;
    expected_catalogue_type: string | null;
    blurb: string | null;
}

interface ApplicationDocument {
    publicId: string;
    kind: string;
    kindLabel: string;
    name: string;
    bytes: number;
    uploadedAt: string;
}

interface DocumentKindOption {
    value: string;
    label: string;
    required: boolean;
}

interface ApplyProps extends SharedPageProps {
    documents: ApplicationDocument[];
    documentKinds: DocumentKindOption[];
    application: {
        reference: string;
        status: string;
        decisionReason: string | null;
        submittedAt: string | null;
        editable: boolean;
        values: ApplicationValues;
    } | null;
}

/**
 * Applying, and seeing where an application stands.
 *
 * `changes_requested` is not a rejection: the form comes back with the
 * previous answers still in it and the reviewer's note above them, so the
 * applicant fixes the one field that was wrong rather than starting over.
 */
export default function Apply() {
    const { application, documents, documentKinds, flash } = usePage<ApplyProps>().props;
    const previous = application?.values;

    const form = useForm({
        legal_name: previous?.legal_name ?? '',
        trading_name: previous?.trading_name ?? '',
        business_type: previous?.business_type ?? '',
        tax_id: '',
        address_line1: previous?.address_line1 ?? '',
        address_line2: '',
        address_city: previous?.address_city ?? '',
        address_state: previous?.address_state ?? '',
        address_postcode: previous?.address_postcode ?? '',
        contact_name: previous?.contact_name ?? '',
        contact_role: '',
        contact_email: previous?.contact_email ?? '',
        contact_phone: previous?.contact_phone ?? '',
        website: previous?.website ?? '',
        expected_catalogue_type: previous?.expected_catalogue_type ?? '',
        blurb: previous?.blurb ?? '',
        terms_accepted: false as boolean,
    });

    const editable = application === null || application.editable;

    return (
        <OnboardingLayout
            title={application === null ? 'Sell on the marketplace' : 'Your application'}
            lede={
                application === null
                    ? 'Tell us who you are and what you intend to sell. A person reads every application; you will hear back by email.'
                    : 'Everything you have told us so far, and where the review stands.'
            }
        >
            <FlashBanner success={flash.success} error={flash.error} />

            {application !== null ? (
                <section className="mb-10 border-2 border-[var(--vc-divider)] p-5">
                    <div className="flex flex-wrap items-center gap-3">
                        <StatusBadge domain="seller_application" value={application.status} />
                        <span className="vc-tabular text-[13px]">{application.reference}</span>
                        {application.submittedAt ? (
                            <span className="text-[13px] text-[var(--vc-neutral-600)]">
                                Submitted {application.submittedAt}
                            </span>
                        ) : null}
                    </div>

                    {application.decisionReason ? (
                        <div className="mt-4 border-l-2 border-[var(--vc-accent)] pl-4">
                            <p className="text-[11px] tracking-[0.08em] text-[var(--vc-neutral-600)] uppercase">
                                From the reviewer
                            </p>
                            <p className="mt-1 max-w-[62ch] text-[14px]">
                                {application.decisionReason}
                            </p>
                        </div>
                    ) : null}

                    {!editable ? (
                        <p className="mt-4 max-w-[62ch] text-[13px] text-[var(--vc-neutral-700)]">
                            {application.status === 'approved'
                                ? 'You are approved. Your seller portal is open.'
                                : 'Your application is with the marketplace team. There is nothing to do until they come back to you.'}
                        </p>
                    ) : null}
                </section>
            ) : null}

            {application !== null ? (
                <div className="mb-10 max-w-[860px]">
                    <DocumentUploader
                        documents={documents}
                        kinds={documentKinds}
                        action="/seller/apply/documents"
                        documentUrl={(publicId) => `/seller/apply/documents/${publicId}`}
                        readOnly={
                            application.status === 'approved' || application.status === 'rejected'
                        }
                    />
                </div>
            ) : null}

            {editable ? (
                <form
                    className="grid max-w-[860px] gap-10 md:grid-cols-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post('/seller/apply', { preserveScroll: true });
                    }}
                >
                    <section className="flex flex-col gap-4">
                        <h2 className="text-[20px]">The business</h2>

                        <Field
                            label="Registered legal name"
                            error={form.errors.legal_name}
                            hint="Exactly as it appears on your registration."
                        >
                            {({ id, describedBy, invalid }) => (
                                <Input
                                    id={id}
                                    aria-describedby={describedBy}
                                    invalid={invalid}
                                    value={form.data.legal_name}
                                    onChange={(event) =>
                                        form.setData('legal_name', event.target.value)
                                    }
                                />
                            )}
                        </Field>

                        <Field
                            label="Trading name"
                            error={form.errors.trading_name}
                            hint="What customers will see."
                        >
                            {({ id, describedBy, invalid }) => (
                                <Input
                                    id={id}
                                    aria-describedby={describedBy}
                                    invalid={invalid}
                                    value={form.data.trading_name}
                                    onChange={(event) =>
                                        form.setData('trading_name', event.target.value)
                                    }
                                />
                            )}
                        </Field>

                        <Field label="Business type" error={form.errors.business_type}>
                            {({ id, describedBy, invalid }) => (
                                <Input
                                    id={id}
                                    aria-describedby={describedBy}
                                    invalid={invalid}
                                    placeholder="Sole trader, private limited company…"
                                    value={form.data.business_type}
                                    onChange={(event) =>
                                        form.setData('business_type', event.target.value)
                                    }
                                />
                            )}
                        </Field>

                        <Field
                            label="Tax registration number"
                            error={form.errors.tax_id}
                            hint="Held for verification. It is never shown to customers, and only reviewers cleared to see it can read it back."
                        >
                            {({ id, describedBy, invalid }) => (
                                <Input
                                    id={id}
                                    autoComplete="off"
                                    aria-describedby={describedBy}
                                    invalid={invalid}
                                    value={form.data.tax_id}
                                    onChange={(event) => form.setData('tax_id', event.target.value)}
                                />
                            )}
                        </Field>

                        <Field label="Address" error={form.errors.address_line1}>
                            {({ id, describedBy, invalid }) => (
                                <Input
                                    id={id}
                                    aria-describedby={describedBy}
                                    invalid={invalid}
                                    value={form.data.address_line1}
                                    onChange={(event) =>
                                        form.setData('address_line1', event.target.value)
                                    }
                                />
                            )}
                        </Field>

                        <Field label="Address line 2" error={form.errors.address_line2}>
                            {({ id, invalid }) => (
                                <Input
                                    id={id}
                                    invalid={invalid}
                                    value={form.data.address_line2}
                                    onChange={(event) =>
                                        form.setData('address_line2', event.target.value)
                                    }
                                />
                            )}
                        </Field>

                        <div className="grid grid-cols-3 gap-3">
                            <Field label="City" error={form.errors.address_city}>
                                {({ id, invalid }) => (
                                    <Input
                                        id={id}
                                        invalid={invalid}
                                        value={form.data.address_city}
                                        onChange={(event) =>
                                            form.setData('address_city', event.target.value)
                                        }
                                    />
                                )}
                            </Field>
                            <Field label="State" error={form.errors.address_state}>
                                {({ id, invalid }) => (
                                    <Input
                                        id={id}
                                        invalid={invalid}
                                        value={form.data.address_state}
                                        onChange={(event) =>
                                            form.setData('address_state', event.target.value)
                                        }
                                    />
                                )}
                            </Field>
                            <Field label="Postcode" error={form.errors.address_postcode}>
                                {({ id, invalid }) => (
                                    <Input
                                        id={id}
                                        invalid={invalid}
                                        value={form.data.address_postcode}
                                        onChange={(event) =>
                                            form.setData('address_postcode', event.target.value)
                                        }
                                    />
                                )}
                            </Field>
                        </div>
                    </section>

                    <section className="flex flex-col gap-4">
                        <h2 className="text-[20px]">Who we talk to</h2>

                        <Field label="Contact name" error={form.errors.contact_name}>
                            {({ id, describedBy, invalid }) => (
                                <Input
                                    id={id}
                                    aria-describedby={describedBy}
                                    invalid={invalid}
                                    value={form.data.contact_name}
                                    onChange={(event) =>
                                        form.setData('contact_name', event.target.value)
                                    }
                                />
                            )}
                        </Field>

                        <Field label="Their role" error={form.errors.contact_role}>
                            {({ id, invalid }) => (
                                <Input
                                    id={id}
                                    invalid={invalid}
                                    value={form.data.contact_role}
                                    onChange={(event) =>
                                        form.setData('contact_role', event.target.value)
                                    }
                                />
                            )}
                        </Field>

                        <Field label="Contact email" error={form.errors.contact_email}>
                            {({ id, describedBy, invalid }) => (
                                <Input
                                    id={id}
                                    type="email"
                                    aria-describedby={describedBy}
                                    invalid={invalid}
                                    value={form.data.contact_email}
                                    onChange={(event) =>
                                        form.setData('contact_email', event.target.value)
                                    }
                                />
                            )}
                        </Field>

                        <Field label="Contact phone" error={form.errors.contact_phone}>
                            {({ id, invalid }) => (
                                <Input
                                    id={id}
                                    type="tel"
                                    invalid={invalid}
                                    value={form.data.contact_phone}
                                    onChange={(event) =>
                                        form.setData('contact_phone', event.target.value)
                                    }
                                />
                            )}
                        </Field>

                        <Field label="Website" error={form.errors.website}>
                            {({ id, invalid }) => (
                                <Input
                                    id={id}
                                    type="url"
                                    invalid={invalid}
                                    placeholder="https://"
                                    value={form.data.website}
                                    onChange={(event) =>
                                        form.setData('website', event.target.value)
                                    }
                                />
                            )}
                        </Field>

                        <h2 className="mt-4 text-[20px]">What you sell</h2>

                        <Field
                            label="Catalogue type"
                            error={form.errors.expected_catalogue_type}
                            hint="Own-brand, resale, handmade, refurbished…"
                        >
                            {({ id, describedBy, invalid }) => (
                                <Input
                                    id={id}
                                    aria-describedby={describedBy}
                                    invalid={invalid}
                                    value={form.data.expected_catalogue_type}
                                    onChange={(event) =>
                                        form.setData('expected_catalogue_type', event.target.value)
                                    }
                                />
                            )}
                        </Field>

                        <Field
                            label="Tell us about the business"
                            error={form.errors.blurb}
                            hint="At least a couple of sentences. This is what the reviewer reads first."
                        >
                            {({ id, describedBy, invalid }) => (
                                <Textarea
                                    id={id}
                                    aria-describedby={describedBy}
                                    invalid={invalid}
                                    value={form.data.blurb}
                                    onChange={(event) => form.setData('blurb', event.target.value)}
                                />
                            )}
                        </Field>

                        <label className="flex items-start gap-2 text-[13px]">
                            <input
                                type="checkbox"
                                className="mt-[3px]"
                                checked={form.data.terms_accepted}
                                onChange={(event) =>
                                    form.setData('terms_accepted', event.target.checked)
                                }
                            />
                            <span>
                                I accept the seller terms, and confirm the details above are
                                accurate.
                                {form.errors.terms_accepted ? (
                                    <span
                                        role="alert"
                                        className="mt-1 block text-[var(--vc-accent-800)]"
                                    >
                                        {form.errors.terms_accepted}
                                    </span>
                                ) : null}
                            </span>
                        </label>

                        <Button
                            type="submit"
                            variant="primary"
                            loading={form.processing}
                            loadingLabel="Submitting…"
                        >
                            {application === null ? 'Submit application' : 'Resubmit application'}
                        </Button>
                    </section>
                </form>
            ) : null}
        </OnboardingLayout>
    );
}
