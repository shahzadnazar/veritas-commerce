import { router, useForm } from '@inertiajs/react';
import { Button } from '../primitives/Button';
import { Field, Select } from '../primitives/Field';

export interface UploadedDocument {
    publicId: string;
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

interface DocumentUploaderProps {
    documents: UploadedDocument[];
    kinds: DocumentKindOption[];
    /** Where a new file is posted. */
    action: string;
    /** Builds the route for one document, given its public id. */
    documentUrl: (publicId: string) => string;
    readOnly?: boolean;
}

function readableSize(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    if (bytes < 1024 * 1024) {
        return `${Math.round(bytes / 1024)} KB`;
    }

    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

/**
 * Attaching verification paperwork.
 *
 * Every control is labelled and reachable from the keyboard, the file
 * input names what is accepted, and errors are attached to the control
 * that caused them rather than shown as a banner at the top.
 */
export function DocumentUploader({
    documents,
    kinds,
    action,
    documentUrl,
    readOnly = false,
}: DocumentUploaderProps) {
    const form = useForm<{ kind: string; document: File | null }>({
        kind: kinds[0]?.value ?? '',
        document: null,
    });

    const outstanding = kinds.filter(
        (kind) => kind.required && !documents.some((document) => document.kindLabel === kind.label),
    );

    return (
        <section className="border-2 border-[var(--vc-divider)] p-5">
            <h2 className="mb-1 text-[20px]">Verification documents</h2>
            <p className="mb-5 max-w-[62ch] text-[13px] text-[var(--vc-neutral-700)]">
                Only the marketplace review team can open these. They are never shown on your store
                page and never linked publicly.
            </p>

            {outstanding.length > 0 ? (
                <div className="mb-5 border-l-2 border-[var(--vc-accent)] pl-4">
                    <p className="text-[11px] tracking-[0.08em] text-[var(--vc-neutral-600)] uppercase">
                        Still needed
                    </p>
                    <ul className="mt-1 list-disc pl-5 text-[13px]">
                        {outstanding.map((kind) => (
                            <li key={kind.value}>{kind.label}</li>
                        ))}
                    </ul>
                </div>
            ) : null}

            {documents.length > 0 ? (
                <ul className="mb-6 border-t-2 border-[var(--vc-text)]">
                    {documents.map((document) => (
                        <li
                            key={document.publicId}
                            className="flex flex-wrap items-center gap-3 border-b border-[var(--vc-divider)] py-3"
                        >
                            <span className="flex-1 text-[14px]">
                                <span className="block font-semibold">{document.kindLabel}</span>
                                <span className="block text-[12px] text-[var(--vc-neutral-600)]">
                                    {document.name} · {readableSize(document.bytes)} ·{' '}
                                    {document.uploadedAt}
                                </span>
                            </span>

                            <a
                                href={documentUrl(document.publicId)}
                                className="text-[13px] underline underline-offset-4"
                            >
                                Download
                            </a>

                            {readOnly ? null : (
                                <Button
                                    variant="destructive"
                                    onClick={() => {
                                        if (window.confirm(`Remove ${document.kindLabel}?`)) {
                                            router.delete(documentUrl(document.publicId), {
                                                preserveScroll: true,
                                            });
                                        }
                                    }}
                                >
                                    Remove
                                </Button>
                            )}
                        </li>
                    ))}
                </ul>
            ) : (
                <p className="mb-6 text-[13px] text-[var(--vc-neutral-600)]">
                    Nothing attached yet.
                </p>
            )}

            {readOnly ? null : (
                <form
                    className="flex flex-col gap-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(action, {
                            forceFormData: true,
                            preserveScroll: true,
                            onSuccess: () => form.reset('document'),
                        });
                    }}
                >
                    <Field label="What is this document?" error={form.errors.kind}>
                        {({ id, describedBy, invalid }) => (
                            <Select
                                id={id}
                                aria-describedby={describedBy}
                                invalid={invalid}
                                value={form.data.kind}
                                onChange={(event) => form.setData('kind', event.target.value)}
                            >
                                {kinds.map((kind) => (
                                    <option key={kind.value} value={kind.value}>
                                        {kind.label}
                                        {kind.required ? ' (required)' : ''}
                                    </option>
                                ))}
                            </Select>
                        )}
                    </Field>

                    <Field label="File" error={form.errors.document} hint="PDF, JPEG, PNG or WebP.">
                        {({ id, describedBy, invalid }) => (
                            <input
                                id={id}
                                type="file"
                                required
                                accept="application/pdf,image/jpeg,image/png,image/webp"
                                aria-describedby={describedBy}
                                aria-invalid={invalid || undefined}
                                className="text-[13px]"
                                onChange={(event) =>
                                    form.setData('document', event.target.files?.[0] ?? null)
                                }
                            />
                        )}
                    </Field>

                    <Button
                        type="submit"
                        variant="secondary"
                        loading={form.processing}
                        loadingLabel="Uploading…"
                        className="self-start"
                    >
                        Attach document
                    </Button>
                </form>
            )}
        </section>
    );
}
