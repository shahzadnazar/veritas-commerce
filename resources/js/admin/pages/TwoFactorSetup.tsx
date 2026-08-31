import { useForm, usePage } from '@inertiajs/react';
import { Button } from '../../design-system/primitives/Button';
import { Field, Input } from '../../design-system/primitives/Field';
import { EmptyState, SuccessState } from '../../design-system/patterns/States';
import type { SharedPageProps } from '../../shared/types';

interface TwoFactorSetupProps extends SharedPageProps {
    enabled: boolean;
    enrolling: boolean;
    recoveryCodesRemaining: number;
    enrolment?: { secret: string; uri: string };
    recoveryCodes?: string[];
}

/**
 * Staff second-factor enrolment.
 *
 * The secret and the recovery codes appear exactly once, in the response
 * that creates them. Nothing here re-reads them from the server, because
 * the server no longer has them in a readable form.
 */
export default function TwoFactorSetup() {
    const { enabled, enrolling, recoveryCodesRemaining, enrolment, recoveryCodes, platform } =
        usePage<TwoFactorSetupProps>().props;

    const startForm = useForm({ password: '' });
    const confirmForm = useForm({ code: '' });
    const regenerateForm = useForm({ password: '' });

    return (
        <div data-density="compact" className="mx-auto max-w-[640px] px-6 py-14">
            <p className="mb-3 text-[11px] font-semibold tracking-[0.11em] text-[var(--vc-accent-700)] uppercase">
                {platform.name} · Staff security
            </p>
            <h1 className="mb-3 text-[38px] leading-[1.05]">Two-factor authentication</h1>
            <p className="mb-8 text-[14px] text-[var(--vc-neutral-700)]">
                Every staff account requires a second factor. Until yours is confirmed, this is the
                only page you can reach.
            </p>

            {recoveryCodes ? (
                <div className="mb-8">
                    <SuccessState
                        title="Save your recovery codes"
                        body="Each code works once, if you lose your authenticator. They are shown here and nowhere else — we only keep hashes."
                    />
                    <ul className="vc-tabular mt-2 grid grid-cols-2 gap-[2px]">
                        {recoveryCodes.map((code) => (
                            <li key={code} className="bg-[var(--vc-surface)] px-3 py-2 text-[14px]">
                                {code}
                            </li>
                        ))}
                    </ul>
                </div>
            ) : null}

            {enabled ? (
                <>
                    <div className="mb-8 border-2 border-[var(--vc-text)] p-5">
                        <h2 className="mb-1 text-[18px]">Enabled</h2>
                        <p className="text-[13px] text-[var(--vc-neutral-700)]">
                            {recoveryCodesRemaining} unused recovery code
                            {recoveryCodesRemaining === 1 ? '' : 's'} remaining.
                        </p>
                    </div>

                    <form
                        className="flex max-w-[420px] flex-col gap-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            regenerateForm.post('/admin/two-factor/recovery-codes');
                        }}
                    >
                        <h2 className="text-[18px]">Regenerate recovery codes</h2>
                        <p className="text-[13px] text-[var(--vc-neutral-700)]">
                            This replaces every code, including unused ones.
                        </p>
                        <Field label="Confirm your password" error={regenerateForm.errors.password}>
                            {({ id, describedBy, invalid }) => (
                                <Input
                                    id={id}
                                    type="password"
                                    autoComplete="current-password"
                                    aria-describedby={describedBy}
                                    invalid={invalid}
                                    value={regenerateForm.data.password}
                                    onChange={(event) =>
                                        regenerateForm.setData('password', event.target.value)
                                    }
                                />
                            )}
                        </Field>
                        <Button
                            type="submit"
                            variant="secondary"
                            loading={regenerateForm.processing}
                            loadingLabel="Regenerating…"
                        >
                            Regenerate codes
                        </Button>
                    </form>
                </>
            ) : enrolment ? (
                <div className="flex flex-col gap-6">
                    <div className="border-2 border-[var(--vc-divider)] p-5">
                        <h2 className="mb-2 text-[18px]">Add this account to your authenticator</h2>
                        <p className="mb-3 text-[13px] text-[var(--vc-neutral-700)]">
                            Scan the setup link, or enter the key by hand. Both disappear when you
                            leave this page.
                        </p>
                        <p className="mb-1 text-[11px] tracking-[0.08em] text-[var(--vc-neutral-600)] uppercase">
                            Setup key
                        </p>
                        <code className="mb-4 block break-all bg-[var(--vc-surface)] p-3 text-[14px]">
                            {enrolment.secret}
                        </code>
                        <p className="mb-1 text-[11px] tracking-[0.08em] text-[var(--vc-neutral-600)] uppercase">
                            Setup link
                        </p>
                        <code className="block break-all bg-[var(--vc-surface)] p-3 text-[12px]">
                            {enrolment.uri}
                        </code>
                    </div>

                    <form
                        className="flex max-w-[300px] flex-col gap-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            confirmForm.post('/admin/two-factor');
                        }}
                    >
                        <Field
                            label="Enter the six-digit code"
                            error={confirmForm.errors.code}
                            hint="This proves the key reached your authenticator."
                        >
                            {({ id, describedBy, invalid }) => (
                                <Input
                                    id={id}
                                    inputMode="numeric"
                                    autoComplete="one-time-code"
                                    maxLength={6}
                                    aria-describedby={describedBy}
                                    invalid={invalid}
                                    value={confirmForm.data.code}
                                    onChange={(event) =>
                                        confirmForm.setData('code', event.target.value)
                                    }
                                />
                            )}
                        </Field>
                        <Button
                            type="submit"
                            variant="primary"
                            loading={confirmForm.processing}
                            loadingLabel="Confirming…"
                        >
                            Confirm and enable
                        </Button>
                    </form>
                </div>
            ) : (
                <form
                    className="flex max-w-[420px] flex-col gap-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        startForm.post('/admin/two-factor/start');
                    }}
                >
                    {enrolling ? (
                        <EmptyState
                            title="Enrolment was started but never confirmed"
                            body="Start again to get a fresh key. The previous one cannot sign anyone in."
                        />
                    ) : null}
                    <Field label="Confirm your password" error={startForm.errors.password}>
                        {({ id, describedBy, invalid }) => (
                            <Input
                                id={id}
                                type="password"
                                autoComplete="current-password"
                                aria-describedby={describedBy}
                                invalid={invalid}
                                value={startForm.data.password}
                                onChange={(event) =>
                                    startForm.setData('password', event.target.value)
                                }
                            />
                        )}
                    </Field>
                    <Button
                        type="submit"
                        variant="primary"
                        loading={startForm.processing}
                        loadingLabel="Starting…"
                    >
                        Start setup
                    </Button>
                </form>
            )}
        </div>
    );
}
