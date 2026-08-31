import { useForm, usePage } from '@inertiajs/react';
import { StorefrontLayout } from '../../../design-system/layout/StorefrontLayout';
import { Button } from '../../../design-system/primitives/Button';
import { Field, Input } from '../../../design-system/primitives/Field';
import type { SharedPageProps } from '../../../shared/types';

interface ProfileProps extends SharedPageProps {
    profile: {
        firstName: string;
        lastName: string;
        email: string;
        phone: string | null;
        marketingOptIn: boolean;
        emailVerified: boolean;
    };
    status?: string;
}

export default function Profile() {
    const { profile, status } = usePage<ProfileProps>().props;

    const details = useForm({
        first_name: profile.firstName,
        last_name: profile.lastName,
        email: profile.email,
        phone: profile.phone ?? '',
        marketing_opt_in: profile.marketingOptIn,
    });

    const password = useForm({ current_password: '', password: '', password_confirmation: '' });

    return (
        <StorefrontLayout>
            <h1 className="mb-8 text-[42px]">Your account</h1>

            {status ? (
                <p
                    role="status"
                    className="mb-8 border-2 border-[var(--vc-text)] px-4 py-3 text-[14px]"
                >
                    {status}
                </p>
            ) : null}

            <div className="grid max-w-[880px] gap-14 md:grid-cols-2">
                <form
                    className="flex flex-col gap-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        details.put('/account');
                    }}
                >
                    <h2 className="text-[22px]">Your details</h2>

                    <Field label="First name" error={details.errors.first_name}>
                        {({ id, describedBy, invalid }) => (
                            <Input
                                id={id}
                                aria-describedby={describedBy}
                                invalid={invalid}
                                value={details.data.first_name}
                                onChange={(event) =>
                                    details.setData('first_name', event.target.value)
                                }
                            />
                        )}
                    </Field>

                    <Field label="Last name" error={details.errors.last_name}>
                        {({ id, describedBy, invalid }) => (
                            <Input
                                id={id}
                                aria-describedby={describedBy}
                                invalid={invalid}
                                value={details.data.last_name}
                                onChange={(event) =>
                                    details.setData('last_name', event.target.value)
                                }
                            />
                        )}
                    </Field>

                    <Field
                        label="Email"
                        error={details.errors.email}
                        hint={
                            profile.emailVerified
                                ? 'Changing this sends a fresh verification link to the new address.'
                                : 'This address is not verified yet.'
                        }
                    >
                        {({ id, describedBy, invalid }) => (
                            <Input
                                id={id}
                                type="email"
                                aria-describedby={describedBy}
                                invalid={invalid}
                                value={details.data.email}
                                onChange={(event) => details.setData('email', event.target.value)}
                            />
                        )}
                    </Field>

                    <Field label="Phone — used for delivery updates" error={details.errors.phone}>
                        {({ id, describedBy, invalid }) => (
                            <Input
                                id={id}
                                type="tel"
                                aria-describedby={describedBy}
                                invalid={invalid}
                                value={details.data.phone}
                                onChange={(event) => details.setData('phone', event.target.value)}
                            />
                        )}
                    </Field>

                    <label className="flex items-start gap-2 text-[13px]">
                        <input
                            type="checkbox"
                            className="mt-1"
                            checked={details.data.marketing_opt_in}
                            onChange={(event) =>
                                details.setData('marketing_opt_in', event.target.checked)
                            }
                        />
                        <span>
                            New arrivals and seller news. Order emails are transactional and always
                            sent.
                        </span>
                    </label>

                    <Button
                        type="submit"
                        variant="primary"
                        loading={details.processing}
                        loadingLabel="Saving…"
                    >
                        Save changes
                    </Button>
                </form>

                <form
                    className="flex flex-col gap-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        password.put('/account/password', { onSuccess: () => password.reset() });
                    }}
                >
                    <h2 className="text-[22px]">Password</h2>

                    <Field label="Current password" error={password.errors.current_password}>
                        {({ id, describedBy, invalid }) => (
                            <Input
                                id={id}
                                type="password"
                                autoComplete="current-password"
                                aria-describedby={describedBy}
                                invalid={invalid}
                                value={password.data.current_password}
                                onChange={(event) =>
                                    password.setData('current_password', event.target.value)
                                }
                            />
                        )}
                    </Field>

                    <Field label="New password" error={password.errors.password}>
                        {({ id, describedBy, invalid }) => (
                            <Input
                                id={id}
                                type="password"
                                autoComplete="new-password"
                                aria-describedby={describedBy}
                                invalid={invalid}
                                value={password.data.password}
                                onChange={(event) =>
                                    password.setData('password', event.target.value)
                                }
                            />
                        )}
                    </Field>

                    <Field label="Confirm new password">
                        {({ id }) => (
                            <Input
                                id={id}
                                type="password"
                                autoComplete="new-password"
                                value={password.data.password_confirmation}
                                onChange={(event) =>
                                    password.setData('password_confirmation', event.target.value)
                                }
                            />
                        )}
                    </Field>

                    <Button
                        type="submit"
                        variant="secondary"
                        loading={password.processing}
                        loadingLabel="Updating…"
                    >
                        Update password
                    </Button>
                </form>
            </div>
        </StorefrontLayout>
    );
}
