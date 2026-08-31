import { useForm, usePage } from '@inertiajs/react';
import { StorefrontLayout } from '../../../design-system/layout/StorefrontLayout';
import { AuthCard } from '../../../design-system/patterns/AuthCard';
import { Button } from '../../../design-system/primitives/Button';
import { Field, Input } from '../../../design-system/primitives/Field';
import type { SharedPageProps } from '../../../shared/types';

interface ResetPasswordProps extends SharedPageProps {
    token: string;
    email: string;
}

export default function ResetPassword() {
    const { token, email } = usePage<ResetPasswordProps>().props;
    const form = useForm({ token, email, password: '', password_confirmation: '' });

    return (
        <StorefrontLayout>
            <AuthCard title="Choose a new password" lede="This link works once, and expires after 60 minutes.">
                <form
                    className="flex flex-col gap-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post('/reset-password');
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

                    <Field
                        label="New password"
                        error={form.errors.password}
                        hint="At least eight characters, and not one that has appeared in a known breach."
                    >
                        {({ id, describedBy, invalid }) => (
                            <Input
                                id={id}
                                type="password"
                                autoComplete="new-password"
                                aria-describedby={describedBy}
                                invalid={invalid}
                                value={form.data.password}
                                onChange={(event) => form.setData('password', event.target.value)}
                            />
                        )}
                    </Field>

                    <Field label="Confirm new password">
                        {({ id }) => (
                            <Input
                                id={id}
                                type="password"
                                autoComplete="new-password"
                                value={form.data.password_confirmation}
                                onChange={(event) => form.setData('password_confirmation', event.target.value)}
                            />
                        )}
                    </Field>

                    <Button type="submit" variant="primary" loading={form.processing} loadingLabel="Saving…">
                        Set new password
                    </Button>
                </form>
            </AuthCard>
        </StorefrontLayout>
    );
}
