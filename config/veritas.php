<?php

declare(strict_types=1);

/*
 * Marketplace policy and platform identity.
 *
 * Every value here is configuration. In particular the clearing period is
 * never written as "+7 days" anywhere in the codebase — the ledger reads it
 * from here, a seller may override it on their account, and a future
 * risk-based rule changes this resolution and nothing else.
 */
return [
    /*
     * Platform identity. Development placeholders are fine here; production
     * values arrive through the environment, and none of this is ever
     * written into application logic.
     */
    'identity' => [
        'display_name' => env('VERITAS_DISPLAY_NAME', 'Veritas Commerce'),
        'legal_name' => env('VERITAS_LEGAL_NAME', 'Veritas Commerce, Inc.'),
        'public_url' => env('VERITAS_PUBLIC_URL', env('APP_URL', 'http://localhost:8000')),
        'support_email' => env('VERITAS_SUPPORT_EMAIL', 'support@veritas.test'),
        'billing_email' => env('VERITAS_BILLING_EMAIL', 'billing@veritas.test'),
        'sender_email' => env('VERITAS_SENDER_EMAIL', env('MAIL_FROM_ADDRESS', 'orders@veritas.test')),
        'sender_name' => env('VERITAS_SENDER_NAME', env('VERITAS_DISPLAY_NAME', 'Veritas Commerce')),
        'business_address' => env('VERITAS_BUSINESS_ADDRESS', '1 Placeholder Way, Portland, OR 97232'),
        'country' => env('VERITAS_COUNTRY', 'US'),
        'timezone' => env('VERITAS_TIMEZONE', env('APP_TIMEZONE', 'UTC')),
    ],

    /*
     * Brand assets. Paths resolve against the media disk, so swapping a
     * logo is an upload rather than a deploy.
     */
    'branding' => [
        'logo_path' => env('VERITAS_LOGO_PATH'),
        'favicon_path' => env('VERITAS_FAVICON_PATH'),
        'email_logo_path' => env('VERITAS_EMAIL_LOGO_PATH'),
        'email_accent' => env('VERITAS_EMAIL_ACCENT', '#ec3013'),
        'email_footer_note' => env('VERITAS_EMAIL_FOOTER_NOTE'),
    ],

    /*
     * Media. The architecture targets Cloudflare R2; nothing outside this
     * key names a provider, so local development uses a local disk and
     * production swaps the value.
     */
    'media' => [
        'disk' => env('VERITAS_MEDIA_DISK', env('FILESYSTEM_DISK', 'local')),
        'max_upload_kb' => (int) env('VERITAS_MAX_UPLOAD_KB', 5120),
        'logo_min_pixels' => 400,
        'banner_min_width' => 1600,
        'banner_min_height' => 400,
        'accepted_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
    ],

    /*
     * Seller onboarding. Document requirements are configuration because
     * KYC rules change by market and by regulator, not by release.
     */
    'sellers' => [
        'required_documents' => array_values(array_filter(
            explode(',', (string) env('VERITAS_REQUIRED_SELLER_DOCUMENTS', '')),
        )),
        'invitation_expiry_days' => (int) env('VERITAS_INVITATION_EXPIRY_DAYS', 14),
    ],

    'money' => [
        'default_currency' => env('VERITAS_DEFAULT_CURRENCY', 'USD'),
    ],

    'commission' => [
        'default_rate_percent' => (string) env('VERITAS_DEFAULT_COMMISSION_RATE', '12.00'),
        'minimum_notice_days' => 7,
        'max_rate_percent' => '30.00',
    ],

    'payouts' => [
        'seller_clearing_period_days' => (int) env('VERITAS_SELLER_CLEARING_PERIOD_DAYS', 7),
        'minimum_minor' => (int) env('VERITAS_MINIMUM_PAYOUT_MINOR', 5000),
    ],

    'inventory' => [
        'low_stock_threshold' => (int) env('VERITAS_LOW_STOCK_THRESHOLD', 5),
        'reservation_ttl_minutes' => (int) env('VERITAS_RESERVATION_TTL_MINUTES', 20),
    ],

    'providers' => [
        'payment' => env('PAYMENT_GATEWAY', 'fake'),
        'search' => env('SEARCH_DRIVER', 'database'),
    ],
];
