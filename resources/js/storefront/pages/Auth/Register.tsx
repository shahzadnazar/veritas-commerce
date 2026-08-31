import { Link, useForm } from '@inertiajs/react';
import { StorefrontLayout } from '../../../design-system/layout/StorefrontLayout';
import { AuthCard } from '../../../design-system/patterns/AuthCard';
import { Button } from '../../../design-system/primitives/Button';
import { Field, Input } from '../../../design-system/primitives/Field';

export default function Register() {
    const form = useForm({
        first_name: '',
        last_name: '',
        email: '',
        password: '',
        password_confirmation: '',
        marketing_opt_in: false,
    });

    return (
        <StorefrontLayout>
            <AuthCard
                title="Create your account"
                lede="One account for every store on the marketplace. You can check out as a guest too — an account just keeps your orders and addresses."
                footer={
                    <>
                        Already have one?{' '}
                        <Link href="/login" className="underline">
                            Sign in
                        </Link>
                    </>
                }
            >
                <form
                    className="flex flex-col gap-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post('/register');
                    }}
                >
                    <div className="grid grid-cols-2 gap-4">
                        <Field label="First name" error={form.errors.first_name}>
                            {({ id, describedBy, invalid }) => (
                                <Input
                                    id={id}
                                    autoComplete="given-name"
                                    aria-describedby={describedBy}
                                    invalid={invalid}
                                    value={form.data.first_name}
                                    onChange={(event) =>
                                        form.setData('first_name', event.target.value)
                                    }
                                />
                            )}
                        </Field>
                        <Field label="Last name" error={form.errors.last_name}>
                            {({ id, describedBy, invalid }) => (
                                <Input
                                    id={id}
                                    autoComplete="family-name"
                                    aria-describedby={describedBy}
                                    invalid={invalid}
                                    value={form.data.last_name}
                                    onChange={(event) =>
                                        form.setData('last_name', event.target.value)
                                    }
                                />
                            )}
                        </Field>
                    </div>

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
                        label="Password"
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

                    <Field label="Confirm password">
                        {({ id }) => (
                            <Input
                                id={id}
                                type="password"
                                autoComplete="new-password"
                                value={form.data.password_confirmation}
                                onChange={(event) =>
                                    form.setData('password_confirmation', event.target.value)
                                }
                            />
                        )}
                    </Field>

                    <label className="flex items-start gap-2 text-[13px]">
                        <input
                            type="checkbox"
                            className="mt-1"
                            checked={form.data.marketing_opt_in}
                            onChange={(event) =>
                                form.setData('marketing_opt_in', event.target.checked)
                            }
                        />
                        <span>
                            Email me new arrivals and seller news. Order emails are sent either way.
                        </span>
                    </label>

                    <Button
                        type="submit"
                        variant="primary"
                        loading={form.processing}
                        loadingLabel="Creating account…"
                    >
                        Create account
                    </Button>
                </form>
            </AuthCard>
        </StorefrontLayout>
    );
}
