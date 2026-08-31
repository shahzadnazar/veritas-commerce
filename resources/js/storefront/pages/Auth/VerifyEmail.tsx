import { useForm, usePage } from '@inertiajs/react';
import { StorefrontLayout } from '../../../design-system/layout/StorefrontLayout';
import { AuthCard } from '../../../design-system/patterns/AuthCard';
import { Button } from '../../../design-system/primitives/Button';
import type { SharedPageProps } from '../../../shared/types';

export default function VerifyEmail() {
    const { status, auth } = usePage<SharedPageProps & { status?: string }>().props;
    const resend = useForm({});
    const logout = useForm({});

    return (
        <StorefrontLayout>
            <AuthCard
                title="Check your email"
                lede={`We've sent a verification link to ${auth.user?.email ?? 'your address'}. It expires in 60 minutes and can only be used once.`}
                status={status}
            >
                <div className="flex flex-wrap gap-2">
                    <Button
                        variant="primary"
                        loading={resend.processing}
                        loadingLabel="Sending…"
                        onClick={() => resend.post('/verify-email/send')}
                    >
                        Send it again
                    </Button>
                    <Button variant="ghost" onClick={() => logout.post('/logout')}>
                        Sign out
                    </Button>
                </div>
            </AuthCard>
        </StorefrontLayout>
    );
}
