/**
 * Props Inertia shares with every page, from HandleInertiaRequests.
 */
export interface SharedPageProps {
    platform: {
        /** Display name from platform settings — never a hard-coded string. */
        name: string;
        supportEmail: string;
        currency: string;
    };
    auth: {
        user: { publicId: string; name: string; email: string } | null;
        seller: { publicId: string; storeName: string; role: string } | null;
        admin: { publicId: string; name: string; role: string } | null;
    };
    flash: { success?: string; error?: string };
    [key: string]: unknown;
}
