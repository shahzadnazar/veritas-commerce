import { Link, useForm, usePage } from '@inertiajs/react';
import { StorefrontLayout } from '../../../design-system/layout/StorefrontLayout';
import { AuthCard } from '../../../design-system/patterns/AuthCard';
import { Button } from '../../../design-system/primitives/Button';
import { Field, Input } from '../../../design-system/primitives/Field';
import type { SharedPageProps } from '../../../shared/types';

export default function ForgotPassword() {
    const { status } = usePage<SharedPageProps & { status?: string }>().props;
    const form = useForm({ email: '' });

    return (
        <StorefrontLayout>
            <AuthCard
                title="Reset your password"
                lede="Enter the email on your account and we'll send a link to set a new one."
                status={status}
                footer={
                    <>
                        For security, the confirmation is the same whether or not an account exists.{' '}
                        <Link href="/login" className="underline">Back to sign in</Link>
                    </>
                }
            >
                <form
                    className="flex flex-col gap-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post('/forgot-password');
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

                    <Button type="submit" variant="primary" loading={form.processing} loadingLabel="Sending…">
                        Send reset link
                    </Button>
                </form>
            </AuthCard>
        </StorefrontLayout>
    );
}
