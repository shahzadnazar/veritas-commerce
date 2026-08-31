import { useForm } from '@inertiajs/react';
import { StorefrontLayout } from '../../../design-system/layout/StorefrontLayout';
import { Button } from '../../../design-system/primitives/Button';
import { Field, Input } from '../../../design-system/primitives/Field';

/**
 * Customer sign-in shell.
 *
 * M0 renders the form and its states; the credential flow is built in M1
 * alongside registration, reset and guest-order claiming.
 */
export default function Login() {
    const form = useForm({ email: '', password: '' });

    return (
        <StorefrontLayout>
            <div className="max-w-[420px]">
                <h1 className="mb-3 text-[44px] leading-[1.05]">Welcome back</h1>
                <p className="mb-7 text-[var(--vc-neutral-700)]">
                    Sign in to track orders, save addresses and check out faster.
                </p>

                <form className="flex flex-col gap-4" onSubmit={(event) => event.preventDefault()}>
                    <Field label="Email" error={form.errors.email}>
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

                    <Button
                        type="submit"
                        variant="primary"
                        loading={form.processing}
                        loadingLabel="Signing in…"
                    >
                        Sign in
                    </Button>
                </form>
            </div>
        </StorefrontLayout>
    );
}
