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
    /*
     * Object storage.
     *
     * Two logical disks rather than per-object ACLs: a public one fronted
     * by a CDN, and a private one with no public route at all. Forgetting
     * an ACL is a class of mistake this split removes — a document written
     * to the private disk cannot accidentally become world-readable.
     *
     * Production points both at S3-compatible object storage (the
     * architecture targets Cloudflare R2). Nothing outside config/ names a
     * provider.
     */
    'storage' => [
        'public_disk' => env('VERITAS_PUBLIC_DISK', 'media'),
        'private_disk' => env('VERITAS_PRIVATE_DISK', 'documents'),

        // Seller registration paperwork: a scan or a PDF, not an image
        // gallery, so it gets its own type list and its own size budget.
        'document_mimes' => ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'],
        'max_document_kb' => (int) env('VERITAS_MAX_DOCUMENT_KB', 10240),

        // How long a signed link to a private object stays valid. Short:
        // it exists to hand one file to one authorised person, not to be
        // pasted into a ticket.
        'signed_url_seconds' => (int) env('VERITAS_SIGNED_URL_SECONDS', 120),
    ],

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

    /*
     * Which databases refuse to be destroyed.
     *
     * Read from config rather than straight from the environment on
     * purpose: once `config:cache` has run, `env()` no longer sees the
     * `.env` file, and a protection list that silently emptied itself in
     * production would be worse than no list at all. Baked in at
     * config-build time, it survives.
     *
     * Deliberately not a default list of names. One deployment's
     * "veritas" is another developer's scratch database, so the
     * environment that knows declares it. `APP_ENV=production` is
     * protected on its own, without needing to be named here.
     */
    /*
     * The platform console.
     *
     * A privileged session is worth less time than a shopping one. This
     * value was already declared in .env.example — with a comment saying
     * staff sessions expire far sooner than customer sessions — and was
     * then read by nothing at all, so administrators were in fact getting
     * the ordinary 120 minutes. M9 found the gap; this is where the
     * setting now actually lives.
     */
    'admin' => [
        'session_lifetime_minutes' => max(1, (int) env('ADMIN_SESSION_LIFETIME', 30)),
    ],

    'database' => [
        /*
         * The three timeouts PostgreSQL ships disabled.
         *
         * All default to zero, which means "wait forever", and forever is
         * how a single slow query becomes an outage: with a hundred
         * connections available, a handful of unbounded statements
         * exhausts the pool and every other request queues behind them.
         * The idle-in-transaction case is worse still — a worker that
         * dies mid-transaction holds its locks and blocks VACUUM until
         * somebody notices, which is where table bloat comes from.
         *
         * Web requests get real limits because a page nobody is waiting
         * for any more should stop costing a connection. Console
         * processes get none, because migrations, backups and the
         * analytics rebuild are supposed to take minutes. The
         * idle-in-transaction limit applies to both: no legitimate path
         * sits inside an open transaction doing nothing for a minute.
         *
         * Zero disables any of them, which is the escape hatch for a
         * deployment that needs one.
         */
        'timeouts' => [
            'statement_ms' => max(0, (int) env('DB_STATEMENT_TIMEOUT_MS', 15_000)),
            'lock_ms' => max(0, (int) env('DB_LOCK_TIMEOUT_MS', 5_000)),
            'idle_in_transaction_ms' => max(0, (int) env('DB_IDLE_TRANSACTION_TIMEOUT_MS', 60_000)),
        ],

        'protected' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('VERITAS_PROTECTED_DATABASES', '')),
        ))),
    ],

    'payouts' => [
        'seller_clearing_period_days' => (int) env('VERITAS_SELLER_CLEARING_PERIOD_DAYS', 7),

        /*
         * The smallest withdrawal the platform will process.
         *
         * A minimum exists because every manual settlement costs a person
         * a few minutes and a bank a fee, and a $0.30 payout costs more to
         * make than it moves. $50.00 is the Phase-1 default and it is
         * configuration, not a literal in an action — a business that
         * decides on $10, or on none at all, changes an environment
         * variable. Setting it to 0 allows any positive amount.
         */
        'minimum_minor' => (int) env('VERITAS_MINIMUM_PAYOUT_MINOR', 5000),

        /*
         * The currency payouts operate in.
         *
         * Phase 1 is USD only and says so; the domain stays currency-aware
         * throughout so adding a second one is a list change rather than a
         * rewrite. A seller holding a currency that is not here sees their
         * balance and is told payouts are not available in it yet — the
         * money is not hidden, it is just not withdrawable.
         */
        'currency' => env('VERITAS_PAYOUT_CURRENCY', 'USD'),
        'supported_currencies' => array_map(
            'trim',
            explode(',', (string) env('VERITAS_PAYOUT_CURRENCIES', 'USD')),
        ),

        // Whether a seller must name where the money goes before asking
        // for it. See Payouts\Support\PayoutPolicy for why it is on.
        'require_destination' => (bool) env('VERITAS_PAYOUT_REQUIRE_DESTINATION', true),
    ],

    /*
     * Discovery: how popularity is scored and when a co-occurrence is
     * evidence rather than a coincidence.
     *
     * Every number a recommendation depends on is here rather than spread
     * through jobs and controllers (§36). Changing what the marketplace
     * considers popular is an edit to this block and a rebuild.
     */
    'recommendations' => [
        /*
         * What each behaviour is worth. Commercial intent outweighs
         * curiosity by design: somebody who bought a thing told us far
         * more than somebody who looked at it, and a "popular" list built
         * from views alone ranks whatever is easiest to stumble upon.
         */
        'weights' => [
            'view' => (int) env('VERITAS_RECO_WEIGHT_VIEW', 1),
            'search_click' => (int) env('VERITAS_RECO_WEIGHT_SEARCH_CLICK', 2),
            'wishlist' => (int) env('VERITAS_RECO_WEIGHT_WISHLIST', 4),
            'cart' => (int) env('VERITAS_RECO_WEIGHT_CART', 6),
            'purchase' => (int) env('VERITAS_RECO_WEIGHT_PURCHASE', 12),
        ],

        // The windows popularity is computed over. Both are kept so a
        // caller can ask for "this week" or "this month" and get an
        // answer that was actually computed over that period (§35).
        'windows' => [7, 30],

        /*
         * How many distinct sessions or orders make a pair evidence.
         *
         * §37 and §38: one shared session is a coincidence. Below the
         * threshold the strategy returns nothing and the fallback chain
         * takes over, rather than a recommendation being fabricated from
         * a single visit.
         */
        'minimum_support' => [
            'viewed_together' => (int) env('VERITAS_RECO_MIN_SUPPORT_VIEWED', 3),
            'bought_together' => (int) env('VERITAS_RECO_MIN_SUPPORT_BOUGHT', 2),
        ],

        // How far a similar-product price may sit from the anchor before
        // it stops counting as a comparable, as a percentage.
        'price_band_percent' => (int) env('VERITAS_RECO_PRICE_BAND_PERCENT', 35),

        // How long a computed set of ids may be reused.
        'cache_seconds' => (int) env('VERITAS_RECO_CACHE_SECONDS', 300),
    ],

    'inventory' => [
        'low_stock_threshold' => (int) env('VERITAS_LOW_STOCK_THRESHOLD', 5),
        'reservation_ttl_minutes' => (int) env('VERITAS_RESERVATION_TTL_MINUTES', 20),
    ],

    /*
     * Checkout policy.
     *
     * Shipping is per seller order because that is what a marketplace
     * ships: two sellers are two parcels. Zero by default — a rate card
     * belongs to the sellers, and inventing one here would be a guess
     * hard-coded into the platform.
     *
     * The payment window is what an unpaid order gets before its stock
     * goes back on the shelf. It is deliberately longer than the
     * reservation TTL a browsing customer gets, and deliberately short
     * enough that an abandoned checkout does not hold a seller's last unit
     * overnight.
     */
    'checkout' => [
        'shipping_per_seller_order_minor' => (int) env('VERITAS_SHIPPING_PER_SELLER_ORDER_MINOR', 0),
        'payment_window_minutes' => (int) env('VERITAS_PAYMENT_WINDOW_MINUTES', 30),
    ],

    /*
     * Payments.
     *
     * The provider is chosen by configuration, never by code: the fake
     * driver is the default so a fresh checkout, a test suite and a CI run
     * all work without credentials, and production sets `stripe`.
     *
     * The payment window belongs to the checkout config; what lives here is
     * how long the platform will keep asking the provider about a payment
     * it has not heard a final answer on.
     */
    'payments' => [
        'provider' => env('PAYMENT_PROVIDER', env('PAYMENT_GATEWAY', 'fake')),

        'stripe' => [
            'key' => env('STRIPE_KEY'),
            'secret' => env('STRIPE_SECRET'),
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
            // Pinned, so a Stripe-side default change cannot alter the
            // shape of a response this adapter parses.
            'api_version' => env('STRIPE_API_VERSION', '2025-08-27.basil'),
        ],

        // How long a customer's browser may keep asking the platform
        // whether a payment finished before it gives up and tells them to
        // check their orders. Bounded on purpose (§59).
        'status_poll_seconds' => (int) env('VERITAS_PAYMENT_POLL_SECONDS', 90),
    ],

    'providers' => [
        'payment' => env('PAYMENT_GATEWAY', 'fake'),
        'search' => env('SEARCH_DRIVER', 'database'),
    ],
];
