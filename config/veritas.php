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
    'identity' => [
        'legal_name' => env('VERITAS_LEGAL_NAME', 'Veritas Commerce, Inc.'),
        'display_name' => env('VERITAS_DISPLAY_NAME', 'Veritas Commerce'),
        'support_email' => env('VERITAS_SUPPORT_EMAIL', 'support@veritas.test'),
        'billing_email' => env('VERITAS_BILLING_EMAIL', 'billing@veritas.test'),
        'business_address' => env('VERITAS_BUSINESS_ADDRESS', ''),
        'country' => env('VERITAS_COUNTRY', 'US'),
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
