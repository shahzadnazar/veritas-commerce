import {
    STATUS_PRESENTATION,
    type StatusDomain,
    type StatusPresentation,
    type StatusTone,
} from './generated/statuses';

export type { StatusDomain, StatusPresentation, StatusTone };

/**
 * The single status → tone lookup for the whole product.
 *
 * Phase 6 of the design review found this mapping duplicated three times,
 * once per application, and warned that the first status added after
 * handoff would only reach one of them. There is now one source: the PHP
 * enums, exported to `generated/statuses.ts` by `php artisan statuses:export`
 * and verified in CI by StatusPresentationTest.
 *
 * Never write a per-screen lookup table. Never add a fifth tone — the
 * system is mono, so status is carried by fill weight and label, not hue.
 */
export function statusPresentation(domain: StatusDomain, value: string): StatusPresentation {
    const domainMap = STATUS_PRESENTATION[domain] as Record<string, StatusPresentation> | undefined;
    const found = domainMap?.[value];

    if (found) {
        return found;
    }

    // An unmapped status is a bug the test suite should already have caught.
    // Render something legible rather than nothing, and say so in the console.
    if (import.meta.env.DEV) {
        console.error(
            `[veritas] No presentation for ${domain}.${value}. ` +
                `Add it to the PHP enum and run: php artisan statuses:export`,
        );
    }

    return { tone: 'inactive', label: value };
}

export function statusTone(domain: StatusDomain, value: string): StatusTone {
    return statusPresentation(domain, value).tone;
}

export function statusLabel(domain: StatusDomain, value: string): string {
    return statusPresentation(domain, value).label;
}
