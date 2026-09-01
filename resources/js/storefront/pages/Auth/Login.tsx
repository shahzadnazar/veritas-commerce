import { Link, useForm, usePage } from '@inertiajs/react';
import { StorefrontLayout } from '../../../design-system/layout/StorefrontLayout';
import { AuthCard } from '../../../design-system/patterns/AuthCard';
import { Button } from '../../../design-system/primitives/Button';
import { Field, Input } from '../../../design-system/primitives/Field';
import type { SharedPageProps } from '../../../shared/types';

export default function Login() {
    const { status } = usePage<SharedPageProps & { status?: string }>().props;
    const form = useForm({ email: '', password: '', remember: false });

    return (
        <StorefrontLayout title="Sign in">
            <AuthCard
                title="Welcome back"
                lede="Sign in to track orders, save addresses and check out faster."
                status={status}
                footer={
                    <>
                        New here?{' '}
                        <Link href="/register" className="underline">
                            Create an account
                        </Link>{' '}
                        — it takes about a minute.
                    </>
                }
            >
                <form
                    className="flex flex-col gap-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post('/login');
                    }}
                >
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

                    <div className="flex items-center justify-between text-[13px]">
                        <label className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                checked={form.data.remember}
                                onChange={(event) => form.setData('remember', event.target.checked)}
                            />
                            Stay signed in
                        </label>
                        <Link href="/forgot-password" className="underline">
                            Forgot your password?
                        </Link>
                    </div>

                    <Button
                        type="submit"
                        variant="primary"
                        loading={form.processing}
                        loadingLabel="Signing in…"
                    >
                        Sign in
                    </Button>
                </form>
            </AuthCard>
        </StorefrontLayout>
    );
}
