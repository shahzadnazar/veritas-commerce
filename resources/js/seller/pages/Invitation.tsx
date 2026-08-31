import { useForm, usePage } from '@inertiajs/react';
import { OnboardingLayout } from '../layouts/OnboardingLayout';
import { Button } from '../../design-system/primitives/Button';
import { ErrorState } from '../../design-system/patterns/States';
import type { SharedPageProps } from '../../shared/types';

interface InvitationProps extends SharedPageProps {
    invitation: {
        publicId: string;
        storeName: string | null;
        role: string;
        email: string;
        status: string;
        redeemable: boolean;
        expiresAt: string;
    };
    token: string;
}

/**
 * Accepting an invitation.
 *
 * The token travels in the link and is posted back; the page shows who the
 * invitation is for, because an invitation only redeems for the address it
 * was sent to and a mismatch should be obvious before the click.
 */
export default function Invitation() {
    const { invitation, token, auth } = usePage<InvitationProps>().props;

    const form = useForm({ token });

    const wrongAccount = auth.user !== null && auth.user.email !== invitation.email;

    return (
        <OnboardingLayout
            title={`Join ${invitation.storeName ?? 'the store'}`}
            lede={`You have been invited as ${invitation.role}. Accepting adds this store to your existing account — you keep one sign-in.`}
        >
            <div className="max-w-[560px]">
                <dl className="mb-8 border-2 border-[var(--vc-divider)] p-5 text-[14px]">
                    <div className="flex justify-between gap-4 py-1">
                        <dt className="text-[var(--vc-neutral-600)]">Invited address</dt>
                        <dd>{invitation.email}</dd>
                    </div>
                    <div className="flex justify-between gap-4 py-1">
                        <dt className="text-[var(--vc-neutral-600)]">Role</dt>
                        <dd>{invitation.role}</dd>
                    </div>
                    <div className="flex justify-between gap-4 py-1">
                        <dt className="text-[var(--vc-neutral-600)]">Expires</dt>
                        <dd>{invitation.expiresAt}</dd>
                    </div>
                </dl>

                {!invitation.redeemable ? (
                    <ErrorState
                        title="This invitation can no longer be used"
                        body="It has already been accepted, withdrawn, or passed its expiry date. Nothing has changed on your account. Ask the store owner to send a new one."
                    />
                ) : wrongAccount ? (
                    <ErrorState
                        title="Signed in as someone else"
                        body={`This invitation was sent to ${invitation.email}, and you are signed in as ${auth.user?.email ?? 'another account'}. Sign in with the invited address to accept it.`}
                    />
                ) : (
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post(`/seller/invitations/${invitation.publicId}`);
                        }}
                    >
                        {form.errors.token ? (
                            <p
                                role="alert"
                                className="mb-4 text-[13px] text-[var(--vc-accent-800)]"
                            >
                                {form.errors.token}
                            </p>
                        ) : null}

                        <Button
                            type="submit"
                            variant="primary"
                            loading={form.processing}
                            loadingLabel="Joining…"
                        >
                            Accept invitation
                        </Button>
                    </form>
                )}
            </div>
        </OnboardingLayout>
    );
}
