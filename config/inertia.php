<?php

declare(strict_types=1);

return [
    'ssr' => [
        // Storefront only: crawlers and first paint need complete HTML.
        // The portals are behind auth and skip SSR entirely.
        'enabled' => (bool) env('INERTIA_SSR_ENABLED', false),
        'url' => env('INERTIA_SSR_URL', 'http://127.0.0.1:13714'),
    ],

    'pages' => [
        // Pages live under one directory per area, so the resolver has
        // three roots rather than the single default.
        'paths' => [
            resource_path('js/storefront/pages'),
            resource_path('js/seller/pages'),
            resource_path('js/admin/pages'),
        ],
        'extensions' => ['tsx'],

        // A controller naming a page that does not exist should fail here,
        // not as a blank screen in the browser.
        'ensure_pages_exist' => true,
    ],

    'testing' => [
        'ensure_pages_exist' => true,
    ],
];
