import { router } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Save, or unsave, one product.
 *
 * The server decides whether it is saved; this only reports what the
 * server said and asks it to change. There is no optimistic local state
 * that survives a failed request, because a heart that stays filled after
 * a save failed is the interface lying to the person using it.
 *
 * A signed-out visitor is sent to sign in rather than shown a disabled
 * button: "you can't do that" is a worse answer than "sign in first",
 * and the wishlist is one of the better reasons to have an account.
 */
export function WishlistButton({
    productPublicId,
    isSaved,
    isAuthenticated,
    label = 'Save for later',
}: {
    productPublicId: string;
    isSaved: boolean;
    isAuthenticated: boolean;
    label?: string;
}) {
    const [busy, setBusy] = useState(false);

    if (!isAuthenticated) {
        return (
            <a
                href="/login"
                className="inline-flex items-center gap-2 border-2 border-[var(--vc-text)] px-4 py-2 text-[14px] font-semibold"
            >
                <span aria-hidden="true">♡</span>
                Sign in to save
            </a>
        );
    }

    const submit = () => {
        setBusy(true);

        const options = {
            preserveScroll: true,
            onFinish: () => setBusy(false),
        };

        if (isSaved) {
            router.delete('/account/wishlist', {
                ...options,
                data: { product: productPublicId },
            });

            return;
        }

        router.post('/account/wishlist', { product: productPublicId }, options);
    };

    return (
        <button
            type="button"
            onClick={submit}
            disabled={busy}
            aria-pressed={isSaved}
            className="inline-flex items-center gap-2 border-2 border-[var(--vc-text)] px-4 py-2 text-[14px] font-semibold disabled:opacity-50"
        >
            <span aria-hidden="true">{isSaved ? '♥' : '♡'}</span>
            {isSaved ? 'Saved' : label}
        </button>
    );
}
