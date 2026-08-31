import { useForm } from '@inertiajs/react';
import { Button } from '../../design-system/primitives/Button';
import { Field, Input } from '../../design-system/primitives/Field';
import { ErrorState } from '../../design-system/patterns/States';

/**
 * Staff sign-in.
 *
 * A separate route, guard and session from the customer surface: an admin
 * is never signed in as a customer at the same time. Two-factor is
 * mandatory, sessions expire after 30 minutes idle, and the error never
 * says which of email or password was wrong.
 */
export default function Login({ error }: { error?: string }) {
    const form = useForm({ email: '', password: '', code: '' });

    return (
        <div data-density="compact" className="flex min-h-screen items-center justify-center px-6">
            <div className="w-full max-w-[420px]">
                <h1 className="mb-3 text-[38px] leading-[1.05]">Sign in to admin</h1>
                <p className="mb-7 text-[14px] text-[var(--vc-neutral-700)]">
                    Staff access only. Sessions expire after 30 minutes of inactivity and every
                    action is attributed to your account.
                </p>

                {error ? (
                    <div className="mb-5">
                        <ErrorState title="We couldn't sign you in" body={error} />
                    </div>
                ) : null}

                <form
                    className="flex flex-col gap-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post('/admin/login');
                    }}
                >
                    <Field label="Work email" error={form.errors.email}>
                        {({ id, describedBy, invalid }) => (
                            <Input
                                id={id}
                                type="email"
                                autoComplete="username"
                                aria-describedby={describedBy}
                                invalid={invalid}
                                value={form.data.email}
                                onChange={(event) => form.setData('email', event.target.value)}
                            />
                        )}
                    </Field>

                    <Field label="Password" error={form.errors.password}>
                        {({ id, describedBy, invalid }) => (
                            <Input
                                id={id}
                                type="password"
                                autoComplete="current-password"
                                aria-describedby={describedBy}
                                invalid={invalid}
                                value={form.data.password}
                                onChange={(event) => form.setData('password', event.target.value)}
                            />
                        )}
                    </Field>

                    <Field label="Two-factor code" hint="Required for every staff account.">
                        {({ id, describedBy }) => (
                            <Input
                                id={id}
                                inputMode="numeric"
                                autoComplete="one-time-code"
                                aria-describedby={describedBy}
                                value={form.data.code}
                                onChange={(event) => form.setData('code', event.target.value)}
                            />
                        )}
                    </Field>

                    <Button
                        type="submit"
                        variant="primary"
                        block
                        loading={form.processing}
                        loadingLabel="Signing in…"
                    >
                        Sign in
                    </Button>
                </form>

                <p className="mt-6 text-[12px] text-[var(--vc-neutral-600)]">
                    Roles: Owner · Operations · Finance · Support. Permissions are enforced
                    server-side; the sidebar only shows what your role can reach.
                </p>
            </div>
        </div>
    );
}
