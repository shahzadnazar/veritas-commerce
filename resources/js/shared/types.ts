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
    /** Server-authoritative basket size for the header. Zero in the portals. */
    cart?: { count: number };
    flash: { success?: string; error?: string };
    /**
     * Validation errors Inertia shares on every page.
     *
     * Read directly when a failure belongs to the request rather than to
     * one field — a review refused because the customer never bought the
     * product is not an error about the rating box.
     */
    errors: Record<string, string>;
    [key: string]: unknown;
}
